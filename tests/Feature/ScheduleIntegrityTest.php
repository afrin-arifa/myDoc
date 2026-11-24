<?php

namespace Tests\Feature;

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\Doctor\DoctorScheduleController;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use App\Services\DoctorSlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesDoctorLocations;
use Tests\TestCase;

/**
 * Phase 5B: a doctor's slots never overlap, removing or pausing weekly availability closes the open times it covered,
 * weekly windows cannot overlap even under concurrent requests, and the timetable loads a bounded date range.
 */
class ScheduleIntegrityTest extends TestCase
{
    use CreatesDoctorLocations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Monday, 2 November 2026 at 08:00 (Asia/Dhaka). The following Mondays are 9 and 16 November.
        $this->travelTo(CarbonImmutable::create(2026, 11, 2, 8, 0, 0, config('app.timezone')));
    }

    public function test_the_timeline_covers_the_hours_the_shown_days_use(): void
    {
        $doctor = $this->doctor();
        $this->slot($doctor->doctorProfile, '2026-11-09 22:15');
        $this->slot($doctor->doctorProfile, '2026-11-17 06:30');
        $this->slot($doctor->doctorProfile, '2026-11-18 20:50');
        $this->slot($doctor->doctorProfile, '2026-11-24 23:50');

        // The timeline always covers 8 AM to 11 PM, so evening slots are shown.
        $this->actingAs($doctor)->get(route('doctor.schedule', ['date' => '2026-11-09', 'view' => 'day']))->assertOk()
            ->assertSee('data-timeline="8-23"', false)->assertSee('10:15 PM - 10:25 PM')->assertSee('--event-top: 1710px', false);
        // Earlier and later slots widen it; a slot ending at midnight still fits.
        $this->actingAs($doctor)->get(route('doctor.schedule', ['date' => '2026-11-16', 'view' => 'week']))->assertOk()
            ->assertSee('data-timeline="6-23"', false)->assertSee('6:30 AM - 6:40 AM')->assertSee('8:50 PM - 9:00 PM');
        $this->actingAs($doctor)->get(route('doctor.schedule', ['date' => '2026-11-24', 'view' => 'day']))->assertOk()
            ->assertSee('data-timeline="8-24"', false)->assertSee('11:50 PM - 12:00 AM');
        // Days without slots show the same hours.
        $this->actingAs($doctor)->get(route('doctor.schedule', ['date' => '2026-12-07', 'view' => 'day']))->assertOk()
            ->assertSee('data-timeline="8-23"', false);
    }

    public function test_past_available_slots_cannot_be_booked_from_the_calendar(): void
    {
        $doctor = $this->doctor();
        $this->slot($doctor->doctorProfile, '2026-11-02 07:00');
        $url = route('doctor.schedule', ['date' => '2026-11-02', 'view' => 'day']);

        $this->actingAs($doctor)->get($url)->assertOk()
            ->assertSee('data-event-type="available"', false)
            ->assertDontSee('Book this available slot');

        $this->slot($doctor->doctorProfile, '2026-11-02 22:50');

        $this->get($url)->assertOk()->assertSee('Book this available slot');
    }

    public function test_the_dashboard_drops_open_times_that_have_passed_but_keeps_appointments(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $this->slot($profile, '2026-11-02 07:00');
        $this->slot($profile, '2026-11-02 08:00');
        $this->slot($profile, '2026-11-02 07:10', 'blocked');
        $this->slot($profile, '2026-11-02 09:00');
        $this->slot($profile, '2026-11-02 09:10', 'blocked');
        $seen = $this->appointment($profile, '2026-11-02 07:20', 'completed');
        $unmarked = $this->appointment($profile, '2026-11-02 07:30', 'confirmed');
        $cancelled = $this->appointment($profile, '2026-11-02 07:40', 'cancelled');

        $response = $this->actingAs($doctor)->get(route('doctor.dashboard'))->assertOk()
            ->assertSeeInOrder(['Open for booking', 'Blocked time', 'Not open for booking']);

        $this->assertSame(
            [$seen->appointment_slot_id, $unmarked->appointment_slot_id],
            $response->viewData('todaySlots')->take(2)->pluck('id')->all(),
        );
        $this->assertSame(
            ['07:20', '07:30', '09:00', '09:10'],
            $response->viewData('todaySlots')->map(fn (AppointmentSlot $slot): string => $slot->starts_at->format('H:i'))->all(),
        );
        $this->assertNotContains($cancelled->appointment_slot_id, $response->viewData('todaySlots')->pluck('id'));
    }

    public function test_the_dashboard_says_when_nothing_is_left_today(): void
    {
        $doctor = $this->doctor();

        $this->actingAs($doctor)->get(route('doctor.dashboard'))->assertOk()->assertSee('Your day is clear');

        $this->slot($doctor->doctorProfile, '2026-11-02 07:00');

        $this->get(route('doctor.dashboard'))->assertOk()->assertSee('Nothing left today')->assertDontSee('Your day is clear');
    }

    public function test_availability_can_be_edited_from_its_row_and_reopens_with_errors(): void
    {
        $doctor = $this->doctor();
        $location = $this->locationFor($doctor->doctorProfile);
        $sunday = $this->window($doctor->doctorProfile, 0, '09:00', '22:00');
        $this->window($doctor->doctorProfile, 4, '09:00', '22:00');
        $url = route('doctor.schedule');
        $payload = ['availability_form' => $sunday->id, 'doctor_location_id' => $location->id, 'day_of_week' => 0, 'start_time' => '17:00', 'end_time' => '23:00', 'is_active' => '1'];

        $this->actingAs($doctor)->get($url)->assertOk()->assertSee('id="edit-availability-'.$sunday->id.'"', false);
        $this->from($url)->patch(route('doctor.availability.update', $sunday), $payload)
            ->assertRedirect($url.'#availability-panel')->assertSessionHasNoErrors();
        $this->assertSame(['17:00', '23:00'], [substr($sunday->fresh()->start_time, 0, 5), substr($sunday->fresh()->end_time, 0, 5)]);

        // Moving it onto Thursday overlaps that window: only the edited row reopens, with the entered times.
        $html = $this->from($url)->followingRedirects()
            ->patch(route('doctor.availability.update', $sunday), [...$payload, 'day_of_week' => 4, 'start_time' => '20:15'])
            ->assertOk()->assertSee('data-initial-schedule-tab="availability"', false)->getContent();

        $this->assertMatchesRegularExpression('/class="collapse show" id="edit-availability-'.$sunday->id.'"/', $html);
        $this->assertSame(1, substr_count($html, 'class="collapse show"'));
        $this->assertSame(1, substr_count($html, 'value="20:15"'));
    }

    public function test_previous_and_next_move_by_the_shown_view(): void
    {
        $doctor = $this->doctor();
        $expected = [
            'day' => ['2027-01-30', '2027-02-01'],
            'week' => ['2027-01-24', '2027-02-07'],
            'month' => ['2026-12-31', '2027-02-28'],
        ];

        foreach ($expected as $view => [$previous, $next]) {
            $html = $this->actingAs($doctor)->get(route('doctor.schedule', ['date' => '2027-01-31', 'view' => $view]))->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/date='.$previous.'"[^>]*aria-label="Previous '.$view.'"/', $html);
            $this->assertMatchesRegularExpression('/date='.$next.'"[^>]*aria-label="Next '.$view.'"/', $html);
        }
    }

    public function test_month_view_counts_slots_and_shows_only_loaded_weeks(): void
    {
        $doctor = $this->doctor();
        $this->slot($doctor->doctorProfile, '2026-11-09 09:00');
        $this->slot($doctor->doctorProfile, '2026-11-09 09:10', 'blocked');

        $this->actingAs($doctor)->get(route('doctor.schedule', ['date' => '2026-11-09', 'view' => 'month']))->assertOk()
            ->assertSee('doctor-month-calendar__count--available', false)
            ->assertSee('doctor-month-calendar__count--blocked', false)
            ->assertSee('data-calendar-date="2026-12-06"', false)
            ->assertDontSee('No slots for this month');

        // February 2027 fits exactly four weeks.
        $this->get(route('doctor.schedule', ['date' => '2027-02-10', 'view' => 'month']))->assertOk()
            ->assertSee('data-calendar-date="2027-02-28"', false)
            ->assertDontSee('data-calendar-date="2027-03-01"', false)
            ->assertSee('No slots for this month');
    }

    public function test_generation_skips_times_that_overlap_slots_left_by_a_moved_window(): void
    {
        $doctor = $this->doctor();
        $window = $this->window($doctor->doctorProfile, 1, '09:00', '10:00');
        $range = ['start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'weekdays' => [1]];

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), $range)->assertSessionHas('status', '6 new appointment slot(s) generated.');

        // The doctor moves Monday to 09:05–10:25 (written directly, so no slot is blocked by the update itself).
        DB::table('doctor_availabilities')->where('id', $window->id)->update(['start_time' => '09:05', 'end_time' => '10:25']);

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), $range)->assertSessionHas('status', '2 new appointment slot(s) generated.');

        $this->assertSame(['09:00', '09:10', '09:20', '09:30', '09:40', '09:50', '10:05', '10:15'], $this->startTimes($doctor->doctorProfile));
        $this->assertNoOverlappingSlots($doctor->doctorProfile);
    }

    public function test_existing_slots_of_every_status_are_respected_and_the_preview_lists_skipped_times(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $this->window($profile, 1, '09:00', '10:00');
        $blocked = $this->slot($profile, '2026-11-09 09:03', 'blocked');
        $booked = $this->slot($profile, '2026-11-09 09:35', 'booked');
        $sameStart = $this->slot($profile, '2026-11-09 09:50');
        $range = ['start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'weekdays' => [1]];

        $preview = $this->actingAs($doctor)->postJson(route('doctor.slots.preview'), $range)
            ->assertOk()
            ->assertJsonPath('total', 6)
            ->assertJsonPath('existing', 1)
            ->assertJsonPath('overlapping', 4)
            ->assertJsonPath('past', 0);

        $overlapping = collect($preview->json('slots'))->where('overlapping', true)->map(fn (array $slot): string => substr($slot['starts_at'], 11, 5))->values()->all();
        $this->assertSame(['09:00', '09:10', '09:30', '09:40'], $overlapping);
        $this->assertSame(3, AppointmentSlot::count(), 'Preview writes nothing.');

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), $range)->assertSessionHas('status', '1 new appointment slot(s) generated.');

        $this->assertSame(['09:03', '09:20', '09:35', '09:50'], $this->startTimes($profile), 'Only 09:20 was free; 09:45–09:50 is back-to-back, not an overlap.');
        $this->assertSame(['blocked', 'booked', 'available'], [$blocked->fresh()->status, $booked->fresh()->status, $sameStart->fresh()->status]);
        $this->assertNoOverlappingSlots($profile);
    }

    public function test_back_to_back_slots_and_other_doctors_slots_do_not_block_generation(): void
    {
        $doctor = $this->doctor();
        $other = $this->doctor();
        $this->window($doctor->doctorProfile, 1, '09:10', '09:30');
        $this->slot($doctor->doctorProfile, '2026-11-09 09:00');
        $this->slot($other->doctorProfile, '2026-11-09 09:15');

        $created = app(DoctorSlotGenerator::class)->generateRange($doctor->doctorProfile, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'), [1], $doctor);

        $this->assertSame(2, $created);
        $this->assertSame(['09:00', '09:10', '09:20'], $this->startTimes($doctor->doctorProfile));
    }

    public function test_overlapping_legacy_windows_never_produce_overlapping_slots(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        // Rows written before overlap validation existed: two Monday windows that overlap.
        $this->window($profile, 1, '09:00', '10:00');
        $this->window($profile, 1, '09:05', '09:45');
        $range = ['start_date' => '2026-11-09', 'end_date' => '2026-11-09', 'weekdays' => [1]];

        $this->actingAs($doctor)->postJson(route('doctor.slots.preview'), $range)
            ->assertOk()
            ->assertJsonPath('total', 10)
            ->assertJsonPath('overlapping', 4);

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), $range)->assertSessionHas('status', '6 new appointment slot(s) generated.');

        $this->assertNoOverlappingSlots($profile);
    }

    public function test_schedule_changes_take_the_doctor_schedule_lock_before_reading_slots_or_windows(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $window = $this->window($profile, 1, '09:00', '10:00');
        $sameWindow = DoctorAvailability::findOrFail($window->id);

        $operations = [
            'generate' => fn () => app(DoctorSlotGenerator::class)->generateRange($profile, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'), [1], $doctor),
            'create' => fn () => app(DoctorAvailabilityService::class)->create($doctor, $profile, ['doctor_location_id' => $this->locationFor($profile)->id, 'day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '10:00'], true),
            'update' => fn () => app(DoctorAvailabilityService::class)->update($doctor, $window, ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00'], true, $profile),
            'delete' => fn () => app(DoctorAvailabilityService::class)->delete($doctor, $sameWindow, $profile),
        ];

        foreach ($operations as $name => $operation) {
            $queries = [];
            DB::listen(function ($query) use (&$queries): void {
                $queries[] = $query->sql;
            });

            $operation();

            $firstProfileRead = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "doctor_profiles" where "doctor_profiles"."id" = ?'));
            $firstScheduleRead = collect($queries)->search(fn (string $sql): bool => str_contains($sql, '"appointment_slots"') || str_contains($sql, 'from "doctor_availabilities"'));
            $this->assertNotFalse($firstProfileRead, "{$name} locks the doctor profile.");
            $this->assertLessThan($firstScheduleRead, $firstProfileRead, "{$name} locks the doctor profile before reading the schedule.");
            DB::flushQueryLog();
            app('events')->forget('Illuminate\Database\Events\QueryExecuted');
        }
    }

    public function test_removing_a_window_removes_only_future_open_slots_it_covered(): void
    {
        // Monday 9 November at 09:25: 09:00 has passed and 09:20 has started.
        $this->travelTo(CarbonImmutable::create(2026, 11, 9, 9, 25, 0, config('app.timezone')));
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $window = $this->window($profile, 1, '09:00', '12:00');
        $this->window($profile, 1, '12:00', '13:00');
        $this->window($profile, 2, '09:00', '12:00');

        $past = $this->slot($profile, '2026-11-09 09:00');
        $started = $this->slot($profile, '2026-11-09 09:20');
        $laterToday = $this->slot($profile, '2026-11-09 09:40');
        $nextWeek = $this->slot($profile, '2026-11-16 09:00');
        $pending = $this->appointment($profile, '2026-11-16 09:10', 'booked');
        $confirmed = $this->appointment($profile, '2026-11-16 09:20', 'confirmed');
        $completed = $this->appointment($profile, '2026-11-02 09:00', 'completed');
        $alreadyBlocked = $this->slot($profile, '2026-11-16 09:30', 'blocked');
        $coveredByAdjacentWindow = $this->slot($profile, '2026-11-16 12:00');
        $outsideTheWindow = $this->slot($profile, '2026-11-16 08:00');
        $tuesday = $this->slot($profile, '2026-11-17 09:00');
        // Inconsistent legacy data: an "available" slot that a confirmed appointment still uses. It must not be blocked.
        $inconsistent = $this->slot($profile, '2026-11-16 09:40');
        $heldAppointment = Appointment::create(['appointment_slot_id' => $inconsistent->id, 'patient_id' => User::factory()->create(['role' => 'patient'])->id, 'status' => 'confirmed', 'consultation_type' => 'physical']);
        // An open slot with a cancelled appointment: kept (blocked) so the appointment keeps its time.
        $withHistory = $this->appointment($profile, '2026-11-16 09:50', 'cancelled');
        $withHistory->slot->update(['status' => 'available']);
        $slotCount = AppointmentSlot::count();

        $this->actingAs($doctor)->delete(route('doctor.availability.destroy', $window))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Availability removed. 3 open future slots outside your weekly schedule were removed. Existing appointments are unchanged.');

        $this->assertNull($laterToday->fresh());
        $this->assertNull($nextWeek->fresh());
        $this->assertSame('blocked', $withHistory->slot->fresh()->status);
        foreach ([$past, $started, $coveredByAdjacentWindow, $outsideTheWindow, $tuesday, $inconsistent] as $untouched) {
            $this->assertSame('available', $untouched->fresh()->status);
        }
        $this->assertSame('blocked', $alreadyBlocked->fresh()->status);
        foreach (['booked' => $pending, 'confirmed' => $confirmed, 'completed' => $completed] as $status => $appointment) {
            $this->assertSame($status, $appointment->fresh()->status);
            $this->assertSame('booked', $appointment->slot->fresh()->status);
        }
        $this->assertSame($slotCount - 2, AppointmentSlot::count(), 'Only unused slots are removed.');
        $this->assertSame(5, Appointment::count());
        $this->assertSame('confirmed', $heldAppointment->fresh()->status);

        $event = DoctorWorkspaceEvent::where('action', DoctorWorkspaceEvent::ACTION_AVAILABILITY_DELETED)->sole();
        $this->assertSame(2, $event->context['removed_slot_count']);
        $this->assertEqualsCanonicalizing([$laterToday->id, $nextWeek->id], $event->context['removed_slot_ids']);
        $this->assertSame([$withHistory->appointment_slot_id], $event->context['blocked_slot_ids']);
    }

    public function test_pausing_removes_open_slots_and_reactivated_hours_can_be_generated_again(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $window = $this->window($profile, 1, '09:00', '10:00');
        app(DoctorSlotGenerator::class)->generateRange($profile, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-16'), [1], $doctor);
        $this->assertSame(12, AppointmentSlot::where('status', 'available')->count());

        $this->actingAs($doctor)->patch(route('doctor.availability.update', $window), ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'is_active' => '0'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status): bool => str_starts_with($status, 'Availability updated. 12 open future slots'));
        $this->assertSame(0, AppointmentSlot::count());

        $this->actingAs($doctor)->patch(route('doctor.availability.update', $window), ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'is_active' => '1'])
            ->assertSessionHas('status', 'Availability updated.');
        $this->assertSame(0, AppointmentSlot::count(), 'Reactivating never creates slots automatically.');
        $this->assertSame(0, DoctorWorkspaceEvent::where('action', DoctorWorkspaceEvent::ACTION_AVAILABILITY_UPDATED)->latest('id')->first()->context['removed_slot_count']);

        $this->assertSame(12, app(DoctorSlotGenerator::class)->generateRange($profile, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-16'), [1], $doctor));
        $patient = User::factory()->create(['role' => 'patient']);
        $this->actingAs($patient)->post(route('appointment-slots.book', AppointmentSlot::orderBy('starts_at')->first()), ['consultation_type' => 'physical'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Appointment::count());
    }

    public function test_shortening_or_moving_a_window_removes_only_the_times_no_longer_covered(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $window = $this->window($profile, 1, '09:00', '10:00');
        app(DoctorSlotGenerator::class)->generateRange($profile, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'), [1], $doctor);
        $service = app(DoctorAvailabilityService::class);

        $this->assertSame(3, $service->update($doctor, $window, ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '09:30'], true, $profile));
        $this->assertSame(['09:00', '09:10', '09:20'], $this->startTimes($profile));

        $this->assertSame(3, $service->update($doctor, $window->fresh(), ['day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '09:30'], true, $profile));
        $this->assertSame(0, AppointmentSlot::count());
    }

    public function test_a_patient_with_a_removed_time_open_is_asked_to_choose_another(): void
    {
        $doctor = $this->doctor();
        $window = $this->window($doctor->doctorProfile, 1, '09:00', '10:00');
        $slot = $this->slot($doctor->doctorProfile, '2026-11-09 09:00');
        app(DoctorAvailabilityService::class)->delete($doctor, $window, $doctor->doctorProfile);
        $this->assertNull($slot->fresh());

        $this->actingAs(User::factory()->create(['role' => 'patient']));
        $this->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])
            ->assertRedirect(route('doctors.index'))->assertSessionHas('warning', AppointmentController::SLOT_UNAVAILABLE_MESSAGE);
        $this->get(route('appointment-slots.confirm', $slot))
            ->assertRedirect(route('doctors.index'))->assertSessionHas('warning', AppointmentController::SLOT_UNAVAILABLE_MESSAGE);
        $this->assertSame(0, Appointment::count());
    }

    public function test_a_slot_booked_before_the_schedule_change_is_never_closed(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $window = $this->window($profile, 1, '09:00', '10:00');
        $slot = $this->slot($profile, '2026-11-09 09:00');
        $patient = User::factory()->create(['role' => 'patient']);

        // The patient's booking commits first; the schedule change that follows must leave that slot alone.
        $this->actingAs($patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])->assertSessionHasNoErrors();
        $this->assertSame(0, app(DoctorAvailabilityService::class)->delete($doctor, $window, $profile));
        $this->assertSame('booked', $slot->fresh()->status);
        $this->assertSame('booked', Appointment::sole()->status);
    }

    public function test_the_service_refuses_overlapping_windows_even_when_form_validation_was_bypassed(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $service = app(DoctorAvailabilityService::class);
        $service->create($doctor, $profile, ['doctor_location_id' => $this->locationFor($profile)->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '12:00'], true);
        $second = $service->create($doctor, $profile, ['doctor_location_id' => $this->locationFor($profile)->id, 'day_of_week' => 1, 'start_time' => '13:00', 'end_time' => '14:00'], true);

        // Both of two simultaneous requests can pass form validation; the second to take the lock is refused here.
        foreach ([
            'overlapping create' => fn () => $service->create($doctor, $profile, ['doctor_location_id' => $this->locationFor($profile)->id, 'day_of_week' => 1, 'start_time' => '11:00', 'end_time' => '13:00'], true),
            'update into an overlap' => fn () => $service->update($doctor, $second, ['day_of_week' => 1, 'start_time' => '11:30', 'end_time' => '14:00'], true, $profile),
        ] as $case => $operation) {
            try {
                $operation();
                $this->fail("{$case} was accepted.");
            } catch (ValidationException $exception) {
                $this->assertSame([DoctorAvailabilityService::OVERLAP_MESSAGE], $exception->errors()['start_time'], $case);
            }
        }

        $this->assertSame([['09:00', '12:00'], ['13:00', '14:00']], DoctorAvailability::orderBy('start_time')->get()->map(fn (DoctorAvailability $window): array => [substr($window->start_time, 0, 5), substr($window->end_time, 0, 5)])->all());
    }

    public function test_adjacent_windows_are_allowed_and_paused_windows_still_count_for_overlap(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $service = app(DoctorAvailabilityService::class);
        // Stored with seconds, as MySQL returns TIME columns.
        DB::table('doctor_availabilities')->insert(['doctor_profile_id' => $profile->id, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '10:00:00', 'slot_duration_minutes' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->window($profile, 2, '09:00', '10:00', isActive: false);

        $service->create($doctor, $profile, ['doctor_location_id' => $this->locationFor($profile)->id, 'day_of_week' => 1, 'start_time' => '10:00', 'end_time' => '11:00'], true);
        $service->create($doctor, $profile, ['doctor_location_id' => $this->locationFor($profile)->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '09:00'], true);
        $this->assertSame(3, DoctorAvailability::where('day_of_week', 1)->count());

        $this->expectException(ValidationException::class);
        $service->create($doctor, $profile, ['doctor_location_id' => $this->locationFor($profile)->id, 'day_of_week' => 2, 'start_time' => '09:30', 'end_time' => '10:30'], true);
    }

    public function test_the_timetable_limits_custom_ranges_to_six_weeks(): void
    {
        $doctor = $this->doctor();
        $profile = $doctor->doctorProfile;
        $first = $this->slot($profile, '2026-11-09 09:00');
        $lastDayOfLimit = $this->slot($profile, '2026-12-20 09:00');
        $farAway = $this->slot($profile, '2027-01-31 09:00');

        $accepted = $this->actingAs($doctor)->get(route('doctor.schedule', ['start' => '2026-11-09', 'end' => '2026-12-20']))->assertOk();
        $this->assertEqualsCanonicalizing([$first->id, $lastDayOfLimit->id], $accepted->viewData('slots')->pluck('id')->all());
        $accepted->assertDontSee('at most '.DoctorScheduleController::MAX_RANGE_DAYS.' days');

        $limited = $this->actingAs($doctor)->get(route('doctor.schedule', ['start' => '2026-11-09', 'end' => '2027-01-31']))->assertOk();
        $limited->assertSee('The timetable can show at most 42 days at a time, so the week view for your chosen date is shown instead.');
        $this->assertSame([$first->id], $limited->viewData('slots')->pluck('id')->all(), 'Only the week of the start date is loaded.');
        $this->assertNotContains($farAway->id, $limited->viewData('slots')->pluck('id')->all());
    }

    private function doctor(): User
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
            'is_accepting_patients' => true,
        ]);

        return $doctor->load('doctorProfile');
    }

    private function window(DoctorProfile $profile, int $dayOfWeek, string $start, string $end, bool $isActive = true): DoctorAvailability
    {
        return DoctorAvailability::create([
            'doctor_profile_id' => $profile->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => $start,
            'end_time' => $end,
            'slot_duration_minutes' => DoctorAvailability::SLOT_DURATION_MINUTES,
            'is_active' => $isActive,
        ]);
    }

    private function slot(DoctorProfile $profile, string $startsAt, string $status = 'available'): AppointmentSlot
    {
        $start = CarbonImmutable::parse($startsAt, config('app.timezone'));

        return AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(DoctorAvailability::SLOT_DURATION_MINUTES), 'status' => $status]);
    }

    private function appointment(DoctorProfile $profile, string $startsAt, string $status): Appointment
    {
        return Appointment::create([
            'appointment_slot_id' => $this->slot($profile, $startsAt, 'booked')->id,
            'patient_id' => User::factory()->create(['role' => 'patient'])->id,
            'status' => $status,
            'consultation_type' => 'physical',
        ]);
    }

    /**
     * @return list<string>
     */
    private function startTimes(DoctorProfile $profile, ?string $status = null): array
    {
        return AppointmentSlot::where('doctor_profile_id', $profile->id)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (AppointmentSlot $slot): string => $slot->starts_at->format('H:i'))
            ->all();
    }

    private function assertNoOverlappingSlots(DoctorProfile $profile): void
    {
        $slots = AppointmentSlot::where('doctor_profile_id', $profile->id)->orderBy('starts_at')->get()->values();

        foreach ($slots as $index => $slot) {
            $next = $slots[$index + 1] ?? null;
            $this->assertTrue($next === null || $next->starts_at->gte($slot->ends_at), "Slot {$slot->starts_at} overlaps the next one.");
        }
    }
}
