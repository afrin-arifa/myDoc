<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentNotification;
use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorLocation;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use App\Services\DoctorSlotGenerator;
use App\Services\WaitlistMatcher;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithAppointmentSms;
use Tests\TestCase;

/**
 * Waiting list (Release 1): joining, automatic hold-and-offer, accepting through normal booking, declining, expiry,
 * staff offers, the doctor's switch, permissions, notifications and the one-pending-offer-per-slot guarantee.
 */
class WaitlistTest extends TestCase
{
    use InteractsWithAppointmentSms;
    use RefreshDatabase;

    private User $doctor;

    private DoctorProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        $this->fakeSms();
        // Monday, 2 November 2026 at 08:00 (Asia/Dhaka).
        $this->travelTo(CarbonImmutable::create(2026, 11, 2, 8, 0, 0, config('app.timezone')));
        [$this->doctor, $this->profile] = $this->doctorWithProfile('Dr. Rowan Hale');
    }

    public function test_a_patient_joins_from_the_doctor_profile_and_sees_the_entry(): void
    {
        $patient = $this->patient('Wendy Waiting');
        $location = DoctorLocation::factory()->create(['doctor_profile_id' => $this->profile->id, 'name' => 'Dhanmondi chamber']);

        $this->actingAs($patient)->get(route('doctors.show', $this->profile))->assertOk()->assertSee('Join the waiting list');
        $this->actingAs($patient)->post(route('patient.waitlist.store', $this->profile), [
            'date_from' => '2026-11-03', 'date_to' => '2026-11-09', 'time_of_day' => 'morning', 'doctor_location_id' => $location->id, 'consultation_type' => 'physical',
        ])->assertRedirect(route('patient.waitlist.index'))->assertSessionHasNoErrors();

        $entry = WaitlistEntry::sole();
        $this->assertSame([$patient->id, $this->profile->id, $location->id, 'morning', 'waiting', '2026-11-03', '2026-11-09'], [$entry->patient_id, $entry->doctor_profile_id, $entry->doctor_location_id, $entry->time_of_day, $entry->status, $entry->date_from->toDateString(), $entry->date_to->toDateString()]);
        $this->actingAs($patient)->get(route('patient.waitlist.index'))->assertOk()->assertSee('Dr. Rowan Hale')->assertSee('Dhanmondi chamber')->assertSee('Morning');
        $this->actingAs($patient)->get(route('doctors.show', $this->profile))->assertSee('You are on Dr. Rowan Hale')->assertDontSee('Join the waiting list');
    }

    public function test_joining_when_a_matching_time_is_already_open_holds_it_right_away(): void
    {
        $patient = $this->patient();
        $evening = $this->slot('2026-11-04 18:00');
        $morning = $this->slot('2026-11-04 09:00');

        $this->actingAs($patient)->post(route('patient.waitlist.store', $this->profile), [
            'date_from' => '2026-11-03', 'date_to' => '2026-11-06', 'time_of_day' => 'evening', 'consultation_type' => 'physical',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['held', 'available'], [$evening->fresh()->status, $morning->fresh()->status]);
        $this->assertSame('offered', WaitlistEntry::sole()->status);
        $this->actingAs($patient)->get(route('patient.waitlist.index'))->assertSee('A time is held for you')->assertSee('View offer');
    }

    public function test_joining_is_validated_and_limited(): void
    {
        $patient = $this->patient();
        [, $otherProfile] = $this->doctorWithProfile('Dr. Other');
        $foreignLocation = DoctorLocation::factory()->create(['doctor_profile_id' => $otherProfile->id]);
        $base = ['date_from' => '2026-11-03', 'date_to' => '2026-11-09', 'consultation_type' => 'physical'];

        $this->actingAs($patient)->post(route('patient.waitlist.store', $this->profile), [...$base, 'date_from' => '2026-11-01'])->assertSessionHasErrors('date_from');
        $this->actingAs($patient)->post(route('patient.waitlist.store', $this->profile), [...$base, 'date_to' => '2026-11-20'])->assertSessionHasErrors('date_to');
        $this->actingAs($patient)->post(route('patient.waitlist.store', $this->profile), [...$base, 'time_of_day' => 'midnight'])->assertSessionHasErrors('time_of_day');
        $this->actingAs($patient)->post(route('patient.waitlist.store', $this->profile), [...$base, 'doctor_location_id' => $foreignLocation->id])->assertSessionHasErrors('doctor_location_id');
        $this->assertSame(0, WaitlistEntry::count());

        $this->actingAs($patient)->post(route('patient.waitlist.store', $this->profile), $base)->assertSessionHasNoErrors();
        $this->actingAs($patient)->post(route('patient.waitlist.store', $this->profile), $base)->assertSessionHasErrors(['waitlist' => WaitlistService::ALREADY_WAITING_MESSAGE]);
        foreach (['Dr. Two', 'Dr. Three'] as $name) {
            $this->actingAs($patient)->post(route('patient.waitlist.store', $this->doctorWithProfile($name)[1]), $base)->assertSessionHasNoErrors();
        }
        $this->actingAs($patient)->post(route('patient.waitlist.store', $otherProfile), $base)->assertSessionHasErrors('waitlist');
        $this->assertSame(3, WaitlistEntry::where('patient_id', $patient->id)->count());

        $this->profile->update(['waitlist_enabled' => false]);
        $this->actingAs($this->patient())->post(route('patient.waitlist.store', $this->profile), $base)->assertSessionHasErrors(['waitlist' => WaitlistService::UNAVAILABLE_MESSAGE]);
        $this->actingAs($this->doctor)->post(route('patient.waitlist.store', $this->profile), $base)->assertForbidden();
    }

    public function test_a_cancelled_time_is_held_for_the_first_waiting_patient_and_they_are_notified(): void
    {
        $slot = $this->slot('2026-11-05 10:00');
        $booker = $this->patient();
        $appointment = $this->appointmentOn($slot, $booker, 'confirmed');
        $first = $this->entry($this->patient('Fiona First', sms: true), joinedMinutesAgo: 30);
        $second = $this->entry($this->patient(), joinedMinutesAgo: 10);

        $this->actingAs($booker)->post(route('appointments.cancel', $appointment))->assertRedirect();

        $offer = WaitlistOffer::sole();
        $this->assertSame([$first->id, $slot->id, 'pending'], [$offer->waitlist_entry_id, $offer->appointment_slot_id, $offer->status]);
        $this->assertSame('held', $slot->fresh()->status);
        $this->assertSame(['offered', 'waiting'], [$first->fresh()->status, $second->fresh()->status]);
        $this->assertEquals(now()->addMinutes(45)->getTimestamp(), $offer->expires_at->getTimestamp());

        $email = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage())
            ->first(fn ($message) => $message->getTo()[0]->getAddress() === $first->patient->email);
        $this->assertNotNull($email);
        $this->assertStringStartsWith('A time with Dr. Rowan Hale is available for you', $email->getSubject());
        $this->assertStringContainsString(route('patient.waitlist.offers.show', $offer), $email->getHtmlBody());
        $this->assertStringNotContainsString($booker->name, $email->getHtmlBody());
        $sms = $this->sms->sentTo('8801712345678');
        $this->assertCount(1, $sms);
        $this->assertStringContainsString('is held for you until 8:45 AM', $sms[0]['message']);
        $this->assertSame(2, AppointmentNotification::where('waitlist_offer_id', $offer->id)->where('status', 'sent')->count());
        $this->assertSame('system', DoctorWorkspaceEvent::where('action', 'waitlist_offered')->sole()->actor_role);
    }

    public function test_patients_who_cannot_take_the_time_are_skipped_and_keep_their_place(): void
    {
        $atLimit = $this->patient();
        foreach (['2026-11-10 09:00', '2026-11-10 10:00', '2026-11-10 11:00'] as $time) {
            $this->appointmentOn($this->slot($time), $atLimit, 'booked');
        }
        $busy = $this->patient();
        [, $otherProfile] = $this->doctorWithProfile('Dr. Other');
        $this->appointmentOn($this->slot('2026-11-05 10:05', profile: $otherProfile), $busy, 'confirmed');
        $wrongTime = $this->entry($this->patient(), timeOfDay: 'evening', joinedMinutesAgo: 50);
        $limitEntry = $this->entry($atLimit, joinedMinutesAgo: 40);
        $busyEntry = $this->entry($busy, joinedMinutesAgo: 30);
        $eligible = $this->entry($this->patient(), joinedMinutesAgo: 20);

        $offer = app(WaitlistMatcher::class)->offerSlot($this->slot('2026-11-05 10:00')->id);

        $this->assertSame($eligible->id, $offer->waitlist_entry_id);
        foreach ([$wrongTime, $limitEntry, $busyEntry] as $skipped) {
            $this->assertSame('waiting', $skipped->fresh()->status);
        }
    }

    public function test_a_held_time_can_only_be_booked_through_its_offer(): void
    {
        $slot = $this->slot('2026-11-05 10:00');
        $entry = $this->entry($this->patient());
        $offer = app(WaitlistMatcher::class)->offerSlot($slot->id);
        $stranger = $this->patient();

        $this->actingAs($stranger)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])->assertRedirect();
        $this->actingAs($this->doctor)->post(route('doctor.appointments.store'), ['patient_email' => $stranger->email, 'slot_id' => $slot->id, 'consultation_type' => 'physical'])->assertSessionHasErrors('slot');
        $this->actingAs($this->doctor)->post(route('doctor.slots.block', $slot))->assertSessionHasErrors();
        $this->actingAs($stranger)->post(route('patient.waitlist.offers.accept', $offer))->assertNotFound();
        $this->actingAs($stranger)->get(route('patient.waitlist.offers.show', $offer))->assertNotFound();
        $this->assertSame(0, Appointment::count());
        $this->assertSame('held', $slot->fresh()->status);
        $this->actingAs($stranger)->get(route('doctors.show', ['doctor' => $this->profile, 'date' => '2026-11-05']))->assertDontSee('10:00 AM');
        $this->assertSame($entry->id, $offer->waitlist_entry_id);
    }

    public function test_accepting_books_the_held_time_through_normal_booking(): void
    {
        $original = $this->appointmentOn($this->slot('2026-10-20 09:00'), $patient = $this->patient(), 'completed');
        $this->profile->update(['follow_up_fee' => 30]);
        $slot = $this->slot('2026-11-05 10:00');
        $entry = $this->entry($patient, followUpOf: $original);
        $offer = app(WaitlistMatcher::class)->offerSlot($slot->id);

        $this->actingAs($patient)->get(route('patient.waitlist.offers.show', $offer))->assertOk()
            ->assertSee('Held for you until')->assertSee('BDT 30.00')->assertSee('Accept and send request');
        $this->actingAs($patient)->post(route('patient.waitlist.offers.accept', $offer))->assertRedirect(route('patient.appointments.index'));

        $appointment = Appointment::where('appointment_slot_id', $slot->id)->sole();
        $this->assertSame(['booked', $patient->id, $original->id, '30.00', 'follow_up'], [$appointment->status, $appointment->patient_id, $appointment->follow_up_of_id, $appointment->consultation_fee, $appointment->fee_type]);
        $this->assertSame('booked', $slot->fresh()->status);
        $this->assertSame([WaitlistOffer::STATUS_ACCEPTED, WaitlistEntry::STATUS_BOOKED, $appointment->id], [$offer->fresh()->status, $entry->fresh()->status, $entry->fresh()->booked_appointment_id]);
        $this->assertSame(['waitlist', $offer->id], [DoctorWorkspaceEvent::where('action', 'booked')->sole()->context['source'], DoctorWorkspaceEvent::where('action', 'booked')->sole()->context['waitlist_offer_id']]);
    }

    public function test_an_expired_offer_cannot_be_accepted_and_moves_to_the_next_patient(): void
    {
        $slot = $this->slot('2026-11-05 10:00');
        $first = $this->entry($this->patient(), joinedMinutesAgo: 20);
        $second = $this->entry($this->patient(), joinedMinutesAgo: 10);
        $offer = app(WaitlistMatcher::class)->offerSlot($slot->id);

        $this->travel(46)->minutes();
        $this->actingAs($first->patient)->post(route('patient.waitlist.offers.accept', $offer))->assertRedirect(route('patient.waitlist.offers.show', $offer))->assertSessionHasErrors('offer');
        $this->assertSame(0, Appointment::count());

        $this->artisan('mydoc:process-waitlist')->assertSuccessful();

        $this->assertSame(WaitlistOffer::STATUS_EXPIRED, $offer->fresh()->status);
        $this->assertSame(['waiting', 1], [$first->fresh()->status, $first->fresh()->expired_offer_count]);
        $next = WaitlistOffer::where('status', 'pending')->sole();
        $this->assertSame([$second->id, $slot->id], [$next->waitlist_entry_id, $next->appointment_slot_id], 'The same time is never offered twice to the same entry.');
        $this->assertSame('held', $slot->fresh()->status);
    }

    public function test_two_unanswered_offers_pause_the_entry_until_the_patient_resumes(): void
    {
        $entry = $this->entry($patient = $this->patient());
        foreach (['2026-11-05 10:00', '2026-11-06 10:00'] as $time) {
            app(WaitlistMatcher::class)->offerSlot($this->slot($time)->id);
            $this->travel(46)->minutes();
            app(WaitlistMatcher::class)->expireDueOffers();
        }

        $this->assertSame(['paused', 2], [$entry->fresh()->status, $entry->fresh()->expired_offer_count]);
        $this->assertNull(app(WaitlistMatcher::class)->offerSlot($this->slot('2026-11-07 10:00')->id), 'Paused entries get no offers.');
        $this->actingAs($patient)->get(route('patient.waitlist.index'))->assertSee('Paused')->assertSee('Resume');

        $this->actingAs($patient)->patch(route('patient.waitlist.resume', $entry))->assertSessionHasNoErrors();
        $this->assertSame([0], [$entry->fresh()->expired_offer_count]);
        $this->assertSame('offered', $entry->fresh()->status, 'Resuming offers a matching open time straight away.');
    }

    public function test_declining_or_leaving_releases_the_time_to_the_next_patient(): void
    {
        $slot = $this->slot('2026-11-05 10:00');
        $first = $this->entry($this->patient(), joinedMinutesAgo: 30);
        $second = $this->entry($this->patient(), joinedMinutesAgo: 20);
        $offer = app(WaitlistMatcher::class)->offerSlot($slot->id);

        $this->actingAs($first->patient)->post(route('patient.waitlist.offers.decline', $offer))->assertRedirect(route('patient.waitlist.index'));
        $this->assertSame(['declined', 'waiting'], [$offer->fresh()->status, $first->fresh()->status]);
        $secondOffer = WaitlistOffer::where('status', 'pending')->sole();
        $this->assertSame($second->id, $secondOffer->waitlist_entry_id);

        $this->actingAs($second->patient)->delete(route('patient.waitlist.destroy', $second))->assertRedirect();
        $this->assertSame(['withdrawn', 'cancelled'], [$secondOffer->fresh()->status, $second->fresh()->status]);
        $this->assertSame('available', $slot->fresh()->status, 'Nobody else is waiting for it, so it is bookable again.');

        $this->actingAs($this->patient())->delete(route('patient.waitlist.destroy', $first))->assertNotFound();
    }

    public function test_the_hold_never_runs_into_the_appointment_and_short_notice_times_stay_open(): void
    {
        $this->entry($this->patient(), from: '2026-11-02');
        $soon = $this->slot('2026-11-02 08:50');
        $tooSoon = $this->slot('2026-11-02 08:40');

        $offer = app(WaitlistMatcher::class)->offerSlot($soon->id);
        $this->assertSame('2026-11-02 08:20', $offer->expires_at->setTimezone(config('app.timezone'))->format('Y-m-d H:i'), 'Ends 30 minutes before the start.');

        $this->entry($this->patient(), from: '2026-11-02');
        $this->assertNull(app(WaitlistMatcher::class)->offerSlot($tooSoon->id));
        $this->assertSame('available', $tooSoon->fresh()->status);
    }

    public function test_staff_offer_and_withdraw_times_and_assistants_work_for_their_doctor_only(): void
    {
        $assistant = User::factory()->create();
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $this->profile->assistants()->attach($assistant->id);
        [$otherDoctor, $otherProfile] = $this->doctorWithProfile('Dr. Other');
        $this->profile->update(['waitlist_enabled' => false]);
        $entry = $this->entry($this->patient('Sam Patient'));
        $slot = $this->slot('2026-11-05 10:00');
        $this->profile->update(['waitlist_enabled' => true]);

        $this->actingAs($this->doctor)->get(route('doctor.schedule'))->assertOk()->assertSee('Patients waiting for a time')->assertSee('Sam Patient')->assertSee('Offer this time');

        $this->actingAs($assistant)->post(route('assistant.workspace.waitlist.offer', [$this->profile, $entry]), ['slot_id' => $slot->id])->assertSessionHasNoErrors();
        $offer = WaitlistOffer::sole();
        $this->assertSame([$assistant->id, 'held'], [$offer->offered_by, $slot->fresh()->status]);
        $this->assertSame('assistant', DoctorWorkspaceEvent::where('action', 'waitlist_offered')->sole()->actor_role);

        $this->actingAs($otherDoctor)->post(route('doctor.waitlist.withdraw', $offer))->assertNotFound();
        $this->actingAs($assistant)->post(route('assistant.workspace.waitlist.offer', [$otherProfile, $entry]), ['slot_id' => $slot->id])->assertForbidden();

        $this->actingAs($this->doctor)->post(route('doctor.waitlist.withdraw', $offer))->assertSessionHasNoErrors();
        $this->assertSame(['withdrawn', 'waiting', 'available'], [$offer->fresh()->status, $entry->fresh()->status, $slot->fresh()->status]);

        $otherSlot = $this->slot('2026-11-05 11:00', profile: $otherProfile);
        $this->actingAs($this->doctor)->post(route('doctor.waitlist.offer', $entry), ['slot_id' => $otherSlot->id])->assertNotFound();
    }

    public function test_turning_the_list_off_withdraws_offers_and_turning_it_on_offers_again(): void
    {
        $slot = $this->slot('2026-11-05 10:00');
        $entry = $this->entry($this->patient());
        $offer = app(WaitlistMatcher::class)->offerSlot($slot->id);

        $this->actingAs($this->doctor)->patch(route('doctor.waitlist.settings'), ['waitlist_enabled' => 0])->assertSessionHasNoErrors();
        $this->assertSame([false, 'withdrawn', 'waiting', 'available'], [$this->profile->fresh()->waitlist_enabled, $offer->fresh()->status, $entry->fresh()->status, $slot->fresh()->status]);
        $this->assertNull(app(WaitlistMatcher::class)->offerSlot($slot->id));

        $later = $this->slot('2026-11-05 11:00');
        $this->actingAs($this->doctor)->patch(route('doctor.waitlist.settings'), ['waitlist_enabled' => 1])->assertSessionHasNoErrors();
        $this->assertSame('held', $later->fresh()->status, 'Open times are offered again once the list is on.');
        $this->assertSame('available', $slot->fresh()->status, 'A time is never offered twice to the same entry.');
        $this->assertSame($entry->id, WaitlistOffer::where('status', 'pending')->sole()->waitlist_entry_id);
        $this->assertSame(['waitlist_settings', 'waitlist_settings'], DoctorWorkspaceEvent::where('action', 'waitlist_settings')->pluck('action')->all());
        $this->actingAs($this->patient())->patch(route('doctor.waitlist.settings'), ['waitlist_enabled' => 0])->assertForbidden();
    }

    public function test_generated_and_already_open_times_are_offered_and_past_entries_end(): void
    {
        $location = DoctorLocation::factory()->create(['doctor_profile_id' => $this->profile->id]);
        $entry = $this->entry($this->patient(), from: '2026-11-09', to: '2026-11-09');
        DoctorAvailability::create(['doctor_profile_id' => $this->profile->id, 'doctor_location_id' => $location->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '09:20', 'slot_duration_minutes' => 10, 'is_active' => true]);

        app(DoctorSlotGenerator::class)->generateRange($this->profile, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-09'), [1], $this->doctor);

        $this->assertSame('offered', $entry->fresh()->status);
        $this->assertSame('09:00', WaitlistOffer::sole()->slot->starts_at->format('H:i'));

        $past = $this->entry($this->patient(), from: '2026-11-02', to: '2026-11-03');
        $this->travelTo(CarbonImmutable::create(2026, 11, 4, 8, 0, 0, config('app.timezone')));
        $this->artisan('mydoc:process-waitlist')->assertSuccessful();
        $this->assertSame('expired', $past->fresh()->status);
    }

    public function test_a_slot_can_never_have_two_pending_offers(): void
    {
        $slot = $this->slot('2026-11-05 10:00');
        $first = $this->entry($this->patient());
        $second = $this->entry($this->patient());
        WaitlistOffer::factory()->create(['waitlist_entry_id' => $first->id, 'appointment_slot_id' => $slot->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        WaitlistOffer::factory()->create(['waitlist_entry_id' => $second->id, 'appointment_slot_id' => $slot->id]);
    }

    public function test_offer_messages_are_skipped_once_the_offer_has_closed(): void
    {
        Queue::fake();
        $entry = $this->entry($this->patient(sms: true));
        $offer = app(WaitlistMatcher::class)->offerSlot($this->slot('2026-11-05 10:00')->id);
        app(WaitlistService::class)->decline($entry->patient, $offer);

        foreach (AppointmentNotification::where('waitlist_offer_id', $offer->id)->get() as $row) {
            (new SendAppointmentNotification($row->id))->handle();
            $this->assertSame(['skipped', 'Offer is no longer open.'], [$row->fresh()->status, $row->fresh()->last_error]);
        }
        $this->assertSame([], $this->sms->sent);
    }

    public function test_admins_have_a_read_only_overview_and_see_offer_delivery_issues(): void
    {
        $entry = $this->entry($this->patient('Wendy Waiting'));
        $offer = app(WaitlistMatcher::class)->offerSlot($this->slot('2026-11-05 10:00')->id);
        AppointmentNotification::where('waitlist_offer_id', $offer->id)->update(['status' => 'exhausted', 'last_error' => 'Email delivery failed.']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.waitlist.index'))->assertOk()->assertSee('Wendy Waiting')->assertSee('Dr. Rowan Hale')->assertSee('Time offered');
        $this->actingAs($admin)->get(route('admin.waitlist.index', ['offer' => $offer->id]))->assertViewHas('entries', fn ($entries) => $entries->pluck('id')->all() === [$entry->id]);
        $this->actingAs($admin)->get(route('admin.sms-issues.index'))->assertOk()->assertSee('Waiting list offer #'.$offer->id)->assertSee('Waiting list offer');
        $this->actingAs($this->doctor)->get(route('admin.waitlist.index'))->assertForbidden();
        $this->actingAs($entry->patient)->get(route('admin.waitlist.index'))->assertForbidden();
    }

    public function test_the_timetable_shows_held_times(): void
    {
        $slot = $this->slot('2026-11-03 10:00');
        $this->entry($this->patient(), from: '2026-11-03');
        app(WaitlistMatcher::class)->offerSlot($slot->id);

        $this->actingAs($this->doctor)->get(route('doctor.schedule', ['date' => '2026-11-03']))->assertOk()
            ->assertSee('doctor-calendar-event--held', false)->assertSee('Held (waiting list)')->assertSee('Held for waiting list');
    }

    public function test_the_waiting_list_task_runs_every_minute_on_one_server(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'mydoc:process-waitlist'));

        $this->assertSame(['* * * * *', true, true, 10], [$event->expression, $event->withoutOverlapping, $event->onOneServer, $event->expiresAt]);
    }

    /**
     * @return array{0: User, 1: DoctorProfile}
     */
    public function test_times_offered_on_the_schedule_follow_the_same_rule_as_automatic_offers(): void
    {
        $location = DoctorLocation::factory()->create(['doctor_profile_id' => $this->profile->id]);
        $slots = collect([
            $this->slot('2026-11-03 09:00'), $this->slot('2026-11-04 13:30'), $this->slot('2026-11-05 18:00'),
            $this->slot('2026-11-07 10:00'), $this->slot('2026-11-12 09:00'), $this->slot('2026-11-20 19:00'),
        ]);
        $slots[3]->update(['doctor_location_id' => $location->id]);
        $slots = AppointmentSlot::query()->orderBy('starts_at')->get();
        $entries = collect([
            $this->entry($this->patient('Any time')),
            $this->entry($this->patient('Mornings'), timeOfDay: 'morning'),
            $this->entry($this->patient('Evenings later'), '2026-11-10', '2026-11-30', 'evening'),
            tap($this->entry($this->patient('One chamber')), fn (WaitlistEntry $entry) => $entry->update(['doctor_location_id' => $location->id])),
            $this->entry($this->patient('Nothing fits'), '2026-12-01', '2026-12-05'),
        ]);

        $offerable = WaitlistEntry::offerableSlots($entries, $slots, 30);

        foreach ($entries as $entry) {
            $this->assertSame($slots->filter(fn (AppointmentSlot $slot) => $entry->wantsSlot($slot))->pluck('id')->values()->all(), $offerable[$entry->id]->pluck('id')->all(), $entry->patient->name);
        }

        $this->assertCount(4, $offerable[$entries[0]->id]);
        $this->assertSame([$slots[0]->id], WaitlistEntry::offerableSlots($entries->take(1), $slots, 1)[$entries[0]->id]->pluck('id')->all(), 'The limit is respected.');
    }

    private function doctorWithProfile(string $name): array
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name]);
        $profile = DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'Physician', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 50, 'is_accepting_patients' => true]);

        return [$doctor->load('doctorProfile'), $profile];
    }

    private function patient(string $name = 'Pat Patient', bool $sms = false): User
    {
        static $phone = 0;

        return User::factory()->create(['role' => 'patient', 'name' => $name, ...($sms ? ['phone' => '017123456'.str_pad((string) (78 + $phone++), 2, '0', STR_PAD_LEFT), 'sms_notifications_enabled' => true] : [])]);
    }

    private function slot(string $startsAt, string $status = 'available', ?DoctorProfile $profile = null): AppointmentSlot
    {
        $start = CarbonImmutable::parse($startsAt, config('app.timezone'));

        return AppointmentSlot::create(['doctor_profile_id' => ($profile ?? $this->profile)->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(10), 'status' => $status]);
    }

    private function appointmentOn(AppointmentSlot $slot, User $patient, string $status): Appointment
    {
        $slot->update(['status' => 'booked']);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => $status, 'consultation_type' => 'physical', 'booked_at' => now()->subDay()]);
    }

    private function entry(User $patient, string $from = '2026-11-03', string $to = '2026-11-09', ?string $timeOfDay = null, int $joinedMinutesAgo = 0, ?Appointment $followUpOf = null): WaitlistEntry
    {
        $entry = WaitlistEntry::factory()->create([
            'patient_id' => $patient->id,
            'doctor_profile_id' => $this->profile->id,
            'date_from' => $from,
            'date_to' => $to,
            'time_of_day' => $timeOfDay,
            'follow_up_of_id' => $followUpOf?->id,
        ]);
        $entry->forceFill(['created_at' => now()->subMinutes($joinedMinutesAgo)])->save();

        return $entry->fresh(['patient']);
    }
}
