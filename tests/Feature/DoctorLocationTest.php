<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorLocation;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Notifications\AppointmentBookedNotification;
use App\Notifications\AppointmentRescheduledNotification;
use App\Services\DoctorAppointmentService;
use App\Services\DoctorAvailabilityService;
use App\Services\DoctorLocationService;
use App\Services\DoctorSlotGenerator;
use App\Services\GoogleCalendarService;
use App\Services\Sms\AppointmentSmsComposer;
use Carbon\CarbonImmutable;
use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 6: practice locations (chambers) — management and permissions, weekly windows and generated slots, what
 * patients see before booking, and where the location appears afterwards.
 */
class DoctorLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private DoctorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        // Monday, 2 November 2026 at 08:00 (Asia/Dhaka). The following Mondays are 9 and 16 November.
        $this->travelTo(CarbonImmutable::create(2026, 11, 2, 8, 0, 0, config('app.timezone')));
        [$this->doctor, $this->profile] = $this->doctorWithProfile('Dr. Rowan Hale');
    }

    public function test_a_doctor_adds_edits_archives_restores_and_deletes_locations(): void
    {
        $this->actingAs($this->doctor)->post(route('doctor.locations.store'), [
            'name' => 'Popular Diagnostic, Dhanmondi', 'address' => 'House 16, Road 2', 'area' => 'Dhanmondi, Dhaka', 'phone' => '+880 2-9669480', 'directions' => '3rd floor, room 305',
        ])->assertRedirect()->assertSessionHas('status', 'Location added. You can now use it in your weekly availability.');
        $location = DoctorLocation::sole();
        $this->assertSame([$this->profile->id, 'Popular Diagnostic, Dhanmondi', 'House 16, Road 2', true], [$location->doctor_profile_id, $location->name, $location->address, $location->is_active]);

        $this->actingAs($this->doctor)->patch(route('doctor.locations.update', $location), ['name' => 'Popular Diagnostic', 'address' => 'House 16, Road 2, Dhanmondi'])->assertSessionHasNoErrors();
        $this->assertSame(['Popular Diagnostic', null, null], [$location->fresh()->name, $location->fresh()->area, $location->fresh()->phone], 'Cleared optional fields are stored as empty.');

        $this->actingAs($this->doctor)->patch(route('doctor.locations.status', $location), ['is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($location->fresh()->is_active);
        $this->actingAs($this->doctor)->patch(route('doctor.locations.status', $location), ['is_active' => 1])->assertSessionHasNoErrors();
        $this->assertTrue($location->fresh()->is_active);

        $this->actingAs($this->doctor)->delete(route('doctor.locations.destroy', $location))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('doctor_locations', 0);

        $events = DoctorWorkspaceEvent::query()->orderBy('id')->get();
        $this->assertSame(['location_created', 'location_updated', 'location_updated', 'location_updated', 'location_deleted'], $events->pluck('action')->all());
        $this->assertSame(['name', 'address', 'area', 'phone', 'directions'], $events[1]->context['changed_fields']);
        $this->assertSame(['is_active' => [true, false]], $events[2]->context['changes']);
        $serialized = $events->pluck('context')->toJson();
        foreach (['Popular', 'House 16', 'Dhanmondi', '9669480', 'room 305'] as $detail) {
            $this->assertStringNotContainsString($detail, $serialized, 'Audit events never store location details.');
        }
    }

    public function test_location_input_is_validated(): void
    {
        $this->location($this->profile, 'Main chamber');

        $this->actingAs($this->doctor)->post(route('doctor.locations.store'), ['name' => '', 'address' => ''])->assertSessionHasErrors(['name', 'address']);
        $this->actingAs($this->doctor)->post(route('doctor.locations.store'), ['name' => 'Second', 'address' => 'Road 1', 'phone' => 'call me'])->assertSessionHasErrors('phone');
        $this->actingAs($this->doctor)->post(route('doctor.locations.store'), ['name' => str_repeat('x', 121), 'address' => 'Road 1'])->assertSessionHasErrors('name');
        $this->actingAs($this->doctor)->post(route('doctor.locations.store'), ['name' => ' Main chamber ', 'address' => 'Road 1'])->assertSessionHasErrors(['name' => DoctorLocationService::NAME_TAKEN_MESSAGE]);
        foreach (['01712345678', '+8801712345678', '02-9669480', '+880 2 966 9480'] as $phone) {
            $this->actingAs($this->doctor)->post(route('doctor.locations.store'), ['name' => 'Chamber '.$phone, 'address' => 'Road 1', 'phone' => $phone])->assertSessionHasNoErrors();
        }

        // Another doctor may use the same name.
        [$other] = $this->doctorWithProfile('Dr. Other');
        $this->actingAs($other)->post(route('doctor.locations.store'), ['name' => 'Main chamber', 'address' => 'Road 9'])->assertSessionHasNoErrors();
    }

    public function test_only_the_doctor_and_assigned_assistants_can_manage_a_doctors_locations(): void
    {
        $location = $this->location($this->profile, 'Main chamber');
        [$otherDoctor, $otherProfile] = $this->doctorWithProfile('Dr. Other');
        $assistant = User::factory()->create();
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $this->profile->assistants()->attach($assistant->id);

        $this->actingAs($assistant)->post(route('assistant.workspace.locations.store', $this->profile), ['name' => 'Second chamber', 'address' => 'Road 3'])->assertSessionHasNoErrors();
        $this->actingAs($assistant)->patch(route('assistant.workspace.locations.update', [$this->profile, $location]), ['name' => 'Renamed', 'address' => 'Road 4'])->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $location->fresh()->name);
        $this->assertSame([$assistant->id, 'assistant'], [DoctorWorkspaceEvent::latest('id')->first()->actor_id, DoctorWorkspaceEvent::latest('id')->first()->actor_role]);

        // An unassigned doctor's workspace, another doctor's location, and other roles are all refused.
        $this->actingAs($assistant)->post(route('assistant.workspace.locations.store', $otherProfile), ['name' => 'X', 'address' => 'Y'])->assertForbidden();
        $this->actingAs($otherDoctor)->patch(route('doctor.locations.update', $location), ['name' => 'Hijacked', 'address' => 'Z'])->assertNotFound();
        $this->actingAs($otherDoctor)->patch(route('doctor.locations.status', $location), ['is_active' => 0])->assertNotFound();
        $this->actingAs($otherDoctor)->delete(route('doctor.locations.destroy', $location))->assertNotFound();
        $patient = User::factory()->create(['role' => 'patient']);
        $this->actingAs($patient)->post(route('doctor.locations.store'), ['name' => 'X', 'address' => 'Y'])->assertForbidden();

        // A forged doctor_profile_id is ignored: the location is created for the signed-in doctor.
        $this->actingAs($otherDoctor)->post(route('doctor.locations.store'), ['doctor_profile_id' => $this->profile->id, 'name' => 'Own', 'address' => 'Road 5'])->assertSessionHasNoErrors();
        $this->assertSame($otherProfile->id, DoctorLocation::where('name', 'Own')->sole()->doctor_profile_id);
        $this->assertSame(['Renamed', 'Second chamber'], $this->profile->locations()->orderBy('name')->pluck('name')->all());
    }

    public function test_weekly_windows_need_one_of_the_doctors_own_active_locations(): void
    {
        $own = $this->location($this->profile, 'Main chamber');
        $archived = $this->location($this->profile, 'Old chamber', isActive: false);
        [, $otherProfile] = $this->doctorWithProfile('Dr. Other');
        $foreign = $this->location($otherProfile, 'Their chamber');
        $window = ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'is_active' => 1];

        $this->actingAs($this->doctor)->post(route('doctor.availability.store'), $window)->assertSessionHasErrors('doctor_location_id');
        foreach ([$archived->id, $foreign->id, 999999] as $locationId) {
            $this->actingAs($this->doctor)->post(route('doctor.availability.store'), [...$window, 'doctor_location_id' => $locationId])
                ->assertSessionHasErrors(['doctor_location_id' => DoctorLocationService::UNUSABLE_MESSAGE]);
        }
        $this->assertDatabaseCount('doctor_availabilities', 0);

        $this->actingAs($this->doctor)->post(route('doctor.availability.store'), [...$window, 'doctor_location_id' => $own->id])->assertSessionHasNoErrors();
        $this->assertSame($own->id, DoctorAvailability::sole()->doctor_location_id);
        $this->assertSame($own->id, DoctorWorkspaceEvent::where('action', 'availability_created')->sole()->context['doctor_location_id']);

        // Overlap is still checked across locations: a doctor cannot be in two chambers at once.
        $second = $this->location($this->profile, 'Second chamber');
        $this->actingAs($this->doctor)->post(route('doctor.availability.store'), [...$window, 'start_time' => '09:30', 'end_time' => '11:00', 'doctor_location_id' => $second->id])
            ->assertSessionHasErrors('start_time');
        $this->actingAs($this->doctor)->post(route('doctor.availability.store'), [...$window, 'start_time' => '10:00', 'end_time' => '11:00', 'doctor_location_id' => $second->id])
            ->assertSessionHasNoErrors();
    }

    public function test_locations_in_use_can_be_archived_only_without_active_windows_and_never_deleted(): void
    {
        $location = $this->location($this->profile, 'Main chamber');
        $window = $this->window($this->profile, $location, 1, '09:00', '10:00');

        $this->actingAs($this->doctor)->patch(route('doctor.locations.status', $location), ['is_active' => 0])
            ->assertSessionHasErrors(['location' => DoctorLocationService::IN_USE_BY_ACTIVE_WINDOW_MESSAGE]);
        $this->actingAs($this->doctor)->delete(route('doctor.locations.destroy', $location))
            ->assertSessionHasErrors(['location' => DoctorLocationService::IN_USE_MESSAGE]);

        $window->update(['is_active' => false]);
        $this->actingAs($this->doctor)->patch(route('doctor.locations.status', $location), ['is_active' => 0])->assertSessionHasNoErrors();
        $this->actingAs($this->doctor)->delete(route('doctor.locations.destroy', $location))->assertSessionHasErrors('location');
        $this->assertDatabaseHas('doctor_locations', ['id' => $location->id, 'is_active' => false]);
    }

    public function test_generated_slots_keep_their_windows_location_and_the_preview_names_it(): void
    {
        $dhanmondi = $this->location($this->profile, 'Dhanmondi chamber');
        $uttara = $this->location($this->profile, 'Uttara chamber');
        $this->window($this->profile, $dhanmondi, 1, '09:00', '09:30');
        $this->window($this->profile, $uttara, 1, '17:00', '17:20');
        $range = ['start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'weekdays' => [1]];

        $preview = $this->actingAs($this->doctor)->postJson(route('doctor.slots.preview'), $range)->assertOk();
        $this->assertSame(['Dhanmondi chamber', 'Dhanmondi chamber', 'Dhanmondi chamber', 'Uttara chamber', 'Uttara chamber'], collect($preview->json('slots'))->pluck('location')->all());

        $this->actingAs($this->doctor)->post(route('doctor.slots.generate'), $range)->assertSessionHas('status', '5 new appointment slot(s) generated.');

        $slots = AppointmentSlot::orderBy('starts_at')->get();
        $this->assertSame([$dhanmondi->id, $dhanmondi->id, $dhanmondi->id, $uttara->id, $uttara->id], $slots->pluck('doctor_location_id')->all());

        // Editing the location's details later changes neither the slots' location nor their times.
        $dhanmondi->update(['name' => 'Dhanmondi chamber (new building)']);
        $this->assertSame([$dhanmondi->id, '09:00'], [$slots->first()->fresh()->doctor_location_id, $slots->first()->fresh()->starts_at->format('H:i')]);
    }

    public function test_moving_a_window_to_another_location_blocks_only_its_open_future_slots(): void
    {
        $old = $this->location($this->profile, 'Old chamber');
        $new = $this->location($this->profile, 'New chamber');
        $window = $this->window($this->profile, $old, 1, '09:00', '09:30');
        app(DoctorSlotGenerator::class)->generateRange($this->profile, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'), [1], $this->doctor);
        [$open, $booked] = AppointmentSlot::orderBy('starts_at')->take(2)->get()->all();
        $appointment = $this->appointmentOn($booked, 'confirmed');
        $legacy = AppointmentSlot::create(['doctor_profile_id' => $this->profile->id, 'starts_at' => '2026-11-16 09:00:00', 'ends_at' => '2026-11-16 09:10:00', 'status' => 'available']);

        $blocked = app(DoctorAvailabilityService::class)->update($this->doctor, $window, ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '09:30', 'doctor_location_id' => $new->id], true, $this->profile);

        $this->assertSame(2, $blocked, 'The two open slots generated for the old chamber.');
        $this->assertSame('blocked', $open->fresh()->status);
        $this->assertSame(['booked', $old->id, 'confirmed'], [$booked->fresh()->status, $booked->fresh()->doctor_location_id, $appointment->fresh()->status], 'Booked appointments keep their slot and location.');
        $this->assertSame('available', $legacy->fresh()->status, 'Slots without a location are not blocked by linking a location.');
        $this->assertSame($new->id, $window->fresh()->doctor_location_id);
        $this->assertSame(['doctor_location_id' => [$old->id, $new->id]], DoctorWorkspaceEvent::where('action', 'availability_updated')->sole()->context['changes']);

        // Updating without a location keeps the current one.
        app(DoctorAvailabilityService::class)->update($this->doctor, $window->fresh(), ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '09:40'], true, $this->profile);
        $this->assertSame($new->id, $window->fresh()->doctor_location_id);
    }

    public function test_patients_see_locations_before_booking_and_can_filter_by_location(): void
    {
        $dhanmondi = $this->location($this->profile, 'Dhanmondi chamber', address: 'House 16, Road 2', area: 'Dhanmondi, Dhaka', directions: '3rd floor');
        $uttara = $this->location($this->profile, 'Uttara chamber', address: 'Sector 7');
        $this->location($this->profile, 'Closed chamber', isActive: false);
        $morning = $this->slot($this->profile, $dhanmondi, '2026-11-09 09:00');
        $evening = $this->slot($this->profile, $uttara, '2026-11-09 17:00');
        $this->slot($this->profile, $uttara, '2026-11-10 17:00');
        $patient = User::factory()->create(['role' => 'patient']);

        $all = $this->actingAs($patient)->get(route('doctors.show', ['doctor' => $this->profile, 'date' => '2026-11-09']))->assertOk();
        $all->assertSee('Practice locations')->assertSee('House 16, Road 2, Dhanmondi, Dhaka')->assertSee('3rd floor')
            ->assertDontSee('Closed chamber')
            ->assertSeeInOrder(['Dhanmondi chamber', '9:00 AM', 'Uttara chamber', '5:00 PM'])
            ->assertSee('aria-label="Book Mon, Nov 9, 2026 at 9:00 AM at Dhanmondi chamber"', false)
            ->assertSee('Choose a location')
            ->assertSee('Tuesday, November 10: 1 open time');

        $filtered = $this->actingAs($patient)->get(route('doctors.show', ['doctor' => $this->profile, 'date' => '2026-11-09', 'location' => $dhanmondi->id]))->assertOk();
        $this->assertSame([$morning->id], $filtered->viewData('slots')->pluck('id')->all());
        $filtered->assertSee('Tuesday, November 10: no open times')->assertSee('location='.$dhanmondi->id, false);

        // An archived or foreign location id shows all locations instead of an error.
        $this->actingAs($patient)->get(route('doctors.show', ['doctor' => $this->profile, 'date' => '2026-11-09', 'location' => 999999]))->assertOk()
            ->assertViewHas('slots', fn ($slots) => $slots->pluck('id')->sort()->values()->all() === [$morning->id, $evening->id]);

        $this->actingAs($patient)->get(route('appointment-slots.confirm', $evening))->assertOk()
            ->assertSeeInOrder(['Location', 'Uttara chamber', 'Sector 7']);
    }

    public function test_the_location_is_shown_after_booking_and_in_notifications_and_the_calendar(): void
    {
        $dhanmondi = $this->location($this->profile, 'Popular Diagnostic Centre Dhanmondi Branch', address: 'House 16, Road 2');
        $uttara = $this->location($this->profile, 'Uttara chamber', address: 'Sector 7');
        $patient = User::factory()->create(['role' => 'patient', 'phone' => '8801712345678', 'sms_notifications_enabled' => true]);
        $appointment = $this->appointmentOn($this->slot($this->profile, $dhanmondi, '2026-11-09 09:00'), 'confirmed', $patient);

        $this->actingAs($patient)->get(route('patient.appointments.show', $appointment))->assertOk()->assertSee('Popular Diagnostic Centre Dhanmondi Branch')->assertSee('House 16, Road 2');
        $this->actingAs($patient)->get(route('patient.appointments.index'))->assertOk()->assertSee('Popular Diagnostic Centre Dhanmondi Branch');
        $this->actingAs($this->doctor)->get(route('doctor.appointments.show', $appointment))->assertOk()->assertSee('Popular Diagnostic Centre Dhanmondi Branch');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.appointments.show', $appointment))->assertOk()->assertSee('Popular Diagnostic Centre Dhanmondi Branch');

        $booked = (new AppointmentBookedNotification($appointment->fresh()))->toMail($this->doctor)->render();
        $this->assertStringContainsString('Popular Diagnostic Centre Dhanmondi Branch — House 16, Road 2', (string) $booked);

        $sms = app(AppointmentSmsComposer::class)->compose(new AppointmentNotification(['type' => 'confirmed', 'context' => ['audience' => 'patient']]), $appointment->fresh());
        $this->assertStringContainsString(' at Popular Diagnostic Centre Dhan is confirmed.', $sms, 'SMS names the chamber, cut to 30 characters.');
        $this->assertStringNotContainsString('House 16', $sms);

        // Rescheduling to another chamber: the email shows both, and the appointment shows the new one.
        $later = $this->slot($this->profile, $uttara, '2026-11-10 17:00');
        app(DoctorAppointmentService::class)->reschedule($this->doctor, $appointment, $later->id);
        $history = $appointment->rescheduleHistories()->sole();
        $mail = (string) (new AppointmentRescheduledNotification($appointment->fresh(), $history))->toMail($patient)->render();
        $this->assertStringContainsString('Popular Diagnostic Centre Dhanmondi Branch — House 16, Road 2', $mail);
        $this->assertStringContainsString('Uttara chamber — Sector 7', $mail);
        $this->actingAs($patient)->get(route('patient.appointments.show', $appointment))->assertSee('Uttara chamber');

        $captured = null;
        $events = \Mockery::mock();
        $events->shouldReceive('insert')->once()->withArgs(function (string $calendarId, Event $event) use (&$captured): bool {
            $captured = $event;

            return true;
        });
        $calendar = \Mockery::mock(Calendar::class);
        $calendar->events = $events;
        (new GoogleCalendarService($calendar))->createEvent(new GoogleCalendarConnection, $appointment->fresh());
        $this->assertSame('Uttara chamber — Sector 7', $captured->getLocation());
        $this->assertSame('myDoc Appointment', $captured->getSummary());
    }

    public function test_the_schedule_page_lists_locations_and_requires_one_for_new_availability(): void
    {
        $this->actingAs($this->doctor)->get(route('doctor.schedule'))->assertOk()
            ->assertSee('Chambers')
            ->assertSee('No locations yet.')
            ->assertSee('Add a location in the');

        $this->location($this->profile, 'Main chamber', address: 'Road 1');
        $this->location($this->profile, 'Old chamber', isActive: false);
        $page = $this->actingAs($this->doctor)->get(route('doctor.schedule'))->assertOk();
        $page->assertSee('Main chamber')->assertSee('Archived')->assertSee('name="doctor_location_id"', false)
            ->assertSee('>Main chamber</option>', false)
            ->assertDontSee('>Old chamber</option>', false);
    }

    public function test_the_service_rechecks_the_location_even_when_form_validation_is_bypassed(): void
    {
        [, $otherProfile] = $this->doctorWithProfile('Dr. Other');
        $foreign = $this->location($otherProfile, 'Their chamber');

        $this->expectException(ValidationException::class);
        app(DoctorAvailabilityService::class)->create($this->doctor, $this->profile, ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'doctor_location_id' => $foreign->id], true);
    }

    /**
     * @return array{0: User, 1: DoctorProfile}
     */
    private function doctorWithProfile(string $name): array
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name]);
        $profile = DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'Physician', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 50, 'is_accepting_patients' => true]);

        return [$doctor->load('doctorProfile'), $profile];
    }

    private function location(DoctorProfile $profile, string $name, bool $isActive = true, string $address = 'Road 1', ?string $area = null, ?string $directions = null): DoctorLocation
    {
        return DoctorLocation::factory()->create(['doctor_profile_id' => $profile->id, 'name' => $name, 'address' => $address, 'area' => $area, 'phone' => null, 'directions' => $directions, 'is_active' => $isActive]);
    }

    private function window(DoctorProfile $profile, DoctorLocation $location, int $day, string $start, string $end): DoctorAvailability
    {
        return DoctorAvailability::create(['doctor_profile_id' => $profile->id, 'doctor_location_id' => $location->id, 'day_of_week' => $day, 'start_time' => $start, 'end_time' => $end, 'slot_duration_minutes' => 10, 'is_active' => true]);
    }

    private function slot(DoctorProfile $profile, DoctorLocation $location, string $startsAt): AppointmentSlot
    {
        $start = CarbonImmutable::parse($startsAt, config('app.timezone'));

        return AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'doctor_location_id' => $location->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(10), 'status' => 'available']);
    }

    private function appointmentOn(AppointmentSlot $slot, string $status, ?User $patient = null): Appointment
    {
        $slot->update(['status' => 'booked']);

        return Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => ($patient ?? User::factory()->create(['role' => 'patient']))->id,
            'status' => $status,
            'consultation_type' => 'physical',
        ]);
    }
}
