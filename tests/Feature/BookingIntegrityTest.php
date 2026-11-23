<?php

namespace Tests\Feature;

use App\Events\AppointmentSlotReleased;
use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\AppointmentSlotRelease;
use App\Services\PendingAppointmentExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Release 1, Phase 3: per-patient booking limits and overlap rules, pending-request expiry, and the single slot-release
 * path with its AppointmentSlotReleased event.
 */
class BookingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const DHAKA = 'Asia/Dhaka';

    private DoctorProfile $doctorA;

    private DoctorProfile $doctorB;

    private User $patient;

    private User $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00', self::DHAKA));
        $this->doctorA = $this->doctor('Dr. Alpha');
        $this->doctorB = $this->doctor('Dr. Beta');
        $this->patient = User::factory()->create(['role' => 'patient', 'email' => 'limit.patient@example.com']);
        $this->assistant = User::factory()->create();
        $this->assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $this->doctorA->assistants()->attach($this->assistant->id);
    }

    public function test_a_patient_can_hold_at_most_three_active_requests_and_the_fourth_is_refused(): void
    {
        $this->assertSame(3, config('booking.max_active_appointments_per_patient'));
        foreach (['2026-11-21 10:00', '2026-11-22 10:00', '2026-11-23 10:00'] as $start) {
            $this->book($this->slot($this->doctorA, $start))->assertSessionHasNoErrors();
        }

        $fourth = $this->slot($this->doctorB, '2026-11-24 10:00');
        $this->book($fourth)->assertSessionHasErrors(['slot' => 'You already have 3 active appointment requests (pending or confirmed). Cancel one, or wait until one has taken place, before booking another.']);
        $this->assertSame(3, Appointment::count());
        $this->assertSame('available', $fourth->fresh()->status);

        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.store', $this->doctorA), [
            'patient_email' => $this->patient->email, 'slot_id' => $this->slot($this->doctorA, '2026-11-25 10:00')->id, 'consultation_type' => 'physical',
        ])->assertSessionHasErrors(['slot' => 'This patient already has 3 active appointments (pending or confirmed), which is the maximum.']);
        $this->assertSame(3, Appointment::count(), 'Staff bookings count towards the same limit.');
    }

    public function test_closed_and_finished_appointments_do_not_count_towards_the_limit(): void
    {
        foreach (['cancelled', 'rejected', 'completed', 'expired'] as $index => $status) {
            $this->appointment($this->doctorA, "2026-12-0{$index} 10:00", $status, ++$index);
        }
        $this->appointment($this->doctorA, '2026-11-19 10:00', 'confirmed');
        $this->appointment($this->doctorB, '2026-11-21 10:00', 'booked');
        $this->appointment($this->doctorB, '2026-11-22 10:00', 'confirmed');

        $this->book($this->slot($this->doctorA, '2026-11-23 10:00'))->assertSessionHasNoErrors();
        $this->book($this->slot($this->doctorA, '2026-11-24 10:00'))->assertSessionHasErrors('slot');
    }

    public function test_overlapping_appointments_are_refused_across_doctors_but_back_to_back_ones_are_allowed(): void
    {
        $this->appointment($this->doctorA, '2026-11-21 10:00', 'confirmed');

        $this->book($this->slot($this->doctorB, '2026-11-21 10:05'))
            ->assertSessionHasErrors(['slot' => 'You already have an appointment that overlaps this time (Sat, Nov 21, 2026 10:00 AM). Choose a different time.']);
        $this->book($this->slot($this->doctorB, '2026-11-21 09:55'))->assertSessionHasErrors('slot');
        $this->book($this->slot($this->doctorB, '2026-11-21 10:00'))->assertSessionHasErrors('slot');

        $this->book($this->slot($this->doctorB, '2026-11-21 10:10'))->assertSessionHasNoErrors();
        $this->book($this->slot($this->doctorB, '2026-11-21 09:50'))->assertSessionHasNoErrors();
        $this->assertSame(3, Appointment::query()->whereIn('status', Appointment::ACTIVE_STATUSES)->count());

        $other = User::factory()->create(['role' => 'patient']);
        $this->actingAs($other)->post(route('appointment-slots.book', $this->slot($this->doctorB, '2026-11-21 10:05', 'available', 1)), ['consultation_type' => 'physical'])
            ->assertSessionHasNoErrors();
    }

    public function test_staff_bookings_and_reschedules_also_respect_the_overlap_rule(): void
    {
        $this->appointment($this->doctorB, '2026-11-21 10:00', 'confirmed');
        $moving = $this->appointment($this->doctorA, '2026-11-22 10:00', 'confirmed');

        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.store', $this->doctorA), [
            'patient_email' => $this->patient->email, 'slot_id' => $this->slot($this->doctorA, '2026-11-21 10:05')->id, 'consultation_type' => 'physical',
        ])->assertSessionHasErrors(['slot' => 'This patient already has an appointment that overlaps this time (Sat, Nov 21, 2026 10:00 AM).']);

        $clash = $this->slot($this->doctorA, '2026-11-21 10:00');
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.reschedule.store', [$this->doctorA, $moving]), ['slot_id' => $clash->id])
            ->assertSessionHasErrors('slot_id');
        $this->assertSame('2026-11-22 10:00', $moving->fresh()->slot->starts_at->format('Y-m-d H:i'));

        $sameTimeNextSlot = $this->slot($this->doctorA, '2026-11-22 10:10');
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.reschedule.store', [$this->doctorA, $moving]), ['slot_id' => $sameTimeNextSlot->id])
            ->assertSessionHasNoErrors();
        $this->assertSame($sameTimeNextSlot->id, $moving->fresh()->appointment_slot_id, 'The appointment being moved never overlaps itself.');
    }

    public function test_pending_requests_expire_when_their_slot_starts_and_release_the_slot(): void
    {
        Event::fake([AppointmentSlotReleased::class]);
        Queue::fake();
        $due = $this->appointment($this->doctorA, '2026-11-20 09:00', 'booked');
        $past = $this->appointment($this->doctorB, '2026-11-19 15:00', 'booked');
        $later = $this->appointment($this->doctorA, '2026-11-20 09:10', 'booked');
        $confirmed = $this->appointment($this->doctorB, '2026-11-20 08:30', 'confirmed');

        $this->artisan('mydoc:expire-pending-appointments')->expectsOutputToContain('2 pending appointment requests expired.')->assertSuccessful();

        $this->assertSame(['expired', 'expired', 'booked', 'confirmed'], [$due->fresh()->status, $past->fresh()->status, $later->fresh()->status, $confirmed->fresh()->status]);
        $this->assertSame(['available', 'available', 'booked', 'booked'], [$due->slot->fresh()->status, $past->slot->fresh()->status, $later->slot->fresh()->status, $confirmed->slot->fresh()->status]);
        $this->assertNull($due->fresh()->active_slot_id, 'An expired appointment no longer holds its slot.');

        $event = DoctorWorkspaceEvent::query()->where('appointment_id', $due->id)->sole();
        $this->assertSame([DoctorWorkspaceEvent::ACTION_EXPIRED, 'system', null, 'booked', 'expired'], [$event->action, $event->actor_role, $event->actor_id, $event->from_status, $event->to_status]);
        $this->assertSame('expiry', $event->context['source']);
        Event::assertDispatched(AppointmentSlotReleased::class, fn (AppointmentSlotReleased $released) => $released->slotId === $due->appointment_slot_id && $released->reason === AppointmentSlotRelease::REASON_EXPIRED && $released->appointmentId === $due->id && $released->actorId === null);
        Event::assertDispatchedTimes(AppointmentSlotReleased::class, 2);

        $this->assertEqualsCanonicalizing(
            [[$due->id, 'email', 'expired', 'expired:patient'], [$past->id, 'email', 'expired', 'expired:patient']],
            AppointmentNotification::all()->map(fn (AppointmentNotification $row): array => [$row->appointment_id, $row->channel, $row->type, $row->transition_key])->all(),
            'Each expired request emails its patient once; nobody else is notified.',
        );
        Queue::assertNotPushed(SyncAppointmentToGoogleCalendar::class);

        $this->actingAs($this->patient)->get(route('patient.appointments.show', $due))->assertOk()->assertSee('Expired');
        $this->actingAs($this->doctorA->user)->post(route('doctor.appointments.confirm', $due))->assertSessionHasErrors('status');
        $this->assertSame('expired', $due->fresh()->status, 'An expired request cannot be confirmed afterwards.');
    }

    public function test_expiry_is_idempotent(): void
    {
        Event::fake([AppointmentSlotReleased::class]);
        $due = $this->appointment($this->doctorA, '2026-11-20 08:50', 'booked');
        $expiry = app(PendingAppointmentExpiry::class);

        $this->assertSame(1, $expiry->expireDue());
        $this->assertSame(0, $expiry->expireDue());
        $this->assertFalse($expiry->expire($due->id, CarbonImmutable::now()));
        $this->artisan('mydoc:expire-pending-appointments')->expectsOutputToContain('0 pending appointment requests expired.');

        $this->assertSame(1, DoctorWorkspaceEvent::query()->where('action', DoctorWorkspaceEvent::ACTION_EXPIRED)->count());
        Event::assertDispatchedTimes(AppointmentSlotReleased::class, 1);
    }

    public function test_a_request_confirmed_before_expiry_runs_is_never_expired(): void
    {
        $request = $this->appointment($this->doctorA, '2026-11-20 09:30', 'booked');
        $this->actingAs($this->doctorA->user)->post(route('doctor.appointments.confirm', $request))->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:40', self::DHAKA));
        $this->assertSame(0, app(PendingAppointmentExpiry::class)->expireDue());
        $this->assertSame('confirmed', $request->fresh()->status);
        $this->assertSame('booked', $request->slot->fresh()->status);
    }

    public function test_a_request_can_be_confirmed_until_just_before_its_slot_starts(): void
    {
        Queue::fake();
        $request = $this->appointment($this->doctorA, '2026-11-20 09:00', 'booked', 1);

        $this->actingAs($this->doctorA->user)->post(route('doctor.appointments.confirm', $request))->assertSessionHasNoErrors();

        $this->assertSame('confirmed', $request->fresh()->status);
        $this->assertSame(1, AppointmentNotification::query()->where('appointment_id', $request->id)->where('type', AppointmentNotification::TYPE_CONFIRMED)->count());
        $this->assertSame(1, DoctorWorkspaceEvent::query()->where('appointment_id', $request->id)->where('action', DoctorWorkspaceEvent::ACTION_CONFIRMED)->count());
        Queue::assertPushed(SyncAppointmentToGoogleCalendar::class, 1);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function startedSlotProvider(): array
    {
        return [
            'exactly at the start' => ['2026-11-20 09:00', 0],
            'one second after the start' => ['2026-11-20 08:59', 59],
            'well after the start' => ['2026-11-20 08:30', 0],
        ];
    }

    #[DataProvider('startedSlotProvider')]
    public function test_a_request_cannot_be_confirmed_once_its_slot_has_started(string $dhakaStart, int $secondOffset): void
    {
        Queue::fake();
        $request = $this->appointment($this->doctorA, $dhakaStart, 'booked', $secondOffset);

        $this->actingAs($this->doctorA->user)->post(route('doctor.appointments.confirm', $request))
            ->assertSessionHasErrors(['status' => 'This appointment time has already started, so the request can no longer be confirmed.']);
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.confirm', [$this->doctorA, $request]))
            ->assertSessionHasErrors(['status' => 'This appointment time has already started, so the request can no longer be confirmed.']);

        $this->assertSame('booked', $request->fresh()->status, 'The request stays pending until the expiry job closes it.');
        $this->assertSame('booked', $request->slot->fresh()->status);
        $this->assertSame(0, AppointmentNotification::count());
        $this->assertSame(0, DoctorWorkspaceEvent::count());
        Queue::assertNotPushed(SyncAppointmentToGoogleCalendar::class);

        $this->assertSame(1, app(PendingAppointmentExpiry::class)->expireDue());
        $this->assertSame('expired', $request->fresh()->status);
        $this->actingAs($this->doctorA->user)->post(route('doctor.appointments.confirm', $request))
            ->assertSessionHasErrors(['status' => 'This appointment cannot be moved to the requested status.']);
        $this->assertSame('expired', $request->fresh()->status);
    }

    public function test_the_start_cutoff_does_not_change_confirmed_appointments_or_rejection(): void
    {
        Queue::fake();
        $confirmed = $this->appointment($this->doctorA, '2026-11-20 08:30', 'confirmed');
        $started = $this->appointment($this->doctorB, '2026-11-20 08:45', 'booked');

        $this->actingAs($this->doctorA->user)->post(route('doctor.appointments.confirm', $confirmed))
            ->assertSessionHasErrors(['status' => 'This appointment cannot be moved to the requested status.']);
        $this->assertSame('confirmed', $confirmed->fresh()->status);
        $this->assertSame('booked', $confirmed->slot->fresh()->status);

        $this->actingAs($this->doctorB->user)->post(route('doctor.appointments.reject', $started))->assertSessionHasNoErrors();
        $this->assertSame('rejected', $started->fresh()->status, 'A started request can still be rejected.');

        $this->assertSame(0, app(PendingAppointmentExpiry::class)->expireDue(), 'A confirmed appointment is never expired.');
        $this->assertSame('confirmed', $confirmed->fresh()->status);
    }

    public function test_the_expiry_command_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'mydoc:expire-pending-appointments'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_every_workflow_releases_its_slot_once_through_the_release_service(): void
    {
        Event::fake([AppointmentSlotReleased::class]);
        $patientCancel = $this->appointment($this->doctorA, '2026-11-25 10:00', 'confirmed');
        $staffCancel = $this->appointment($this->doctorA, '2026-11-26 10:00', 'confirmed', 2);
        $reject = $this->appointment($this->doctorA, '2026-11-27 10:00', 'booked', 3);
        $reschedule = $this->appointment($this->doctorA, '2026-11-28 10:00', 'confirmed', 4);
        $newSlot = $this->slot($this->doctorA, '2026-11-29 10:00');
        $blocked = $this->slot($this->doctorA, '2026-11-30 10:00', 'blocked');
        $doctor = $this->doctorA->user;

        $this->actingAs($this->patient)->post(route('appointments.cancel', $patientCancel))->assertSessionHasNoErrors();
        $this->actingAs($doctor)->post(route('doctor.appointments.cancel', $staffCancel), ['reason' => 'Doctor unavailable'])->assertSessionHasNoErrors();
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.reject', [$this->doctorA, $reject]))->assertSessionHasNoErrors();
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.reschedule.store', [$this->doctorA, $reschedule]), ['slot_id' => $newSlot->id])->assertSessionHasNoErrors();
        $this->actingAs($doctor)->delete(route('doctor.slots.unblock', $blocked))->assertSessionHasNoErrors();

        $expected = [
            [$patientCancel->appointment_slot_id, AppointmentSlotRelease::REASON_PATIENT_CANCELLED, $this->patient->id],
            [$staffCancel->appointment_slot_id, AppointmentSlotRelease::REASON_STAFF_CANCELLED, $doctor->id],
            [$reject->appointment_slot_id, AppointmentSlotRelease::REASON_REJECTED, $this->assistant->id],
            [$reschedule->appointment_slot_id, AppointmentSlotRelease::REASON_RESCHEDULED, $this->assistant->id],
            [$blocked->id, AppointmentSlotRelease::REASON_UNBLOCKED, $doctor->id],
        ];
        foreach ($expected as [$slotId, $reason, $actorId]) {
            $this->assertSame('available', AppointmentSlot::find($slotId)->status, $reason);
            Event::assertDispatched(AppointmentSlotReleased::class, fn (AppointmentSlotReleased $event) => $event->slotId === $slotId && $event->reason === $reason && $event->actorId === $actorId && $event->doctorProfileId === $this->doctorA->id);
        }
        Event::assertDispatchedTimes(AppointmentSlotReleased::class, 5);
        $this->assertSame('booked', $newSlot->fresh()->status);
        $this->assertSame($newSlot->id, $reschedule->fresh()->appointment_slot_id);
    }

    public function test_releasing_twice_or_a_slot_still_in_use_does_nothing(): void
    {
        Event::fake([AppointmentSlotReleased::class]);
        $release = app(AppointmentSlotRelease::class);
        $held = $this->appointment($this->doctorA, '2026-11-25 10:00', 'confirmed');
        $blocked = $this->slot($this->doctorA, '2026-11-26 10:00', 'blocked');

        DB::transaction(function () use ($release, $held, $blocked): void {
            $this->assertFalse($release->release($held->appointment_slot_id, AppointmentSlotRelease::REASON_STAFF_CANCELLED), 'A confirmed appointment still holds the slot.');
            $this->assertFalse($release->release($blocked, AppointmentSlotRelease::REASON_STAFF_CANCELLED), 'An appointment ending never opens a blocked slot.');

            $held->update(['status' => 'cancelled']);
            $this->assertTrue($release->release($held->appointment_slot_id, AppointmentSlotRelease::REASON_STAFF_CANCELLED));
            $this->assertFalse($release->release($held->appointment_slot_id, AppointmentSlotRelease::REASON_STAFF_CANCELLED), 'Already released.');
        });

        Event::assertDispatchedTimes(AppointmentSlotReleased::class, 1);
        $this->assertSame('blocked', $blocked->fresh()->status);

        $this->expectException(\LogicException::class);
        DB::transaction(fn () => $release->release($blocked, 'waiting_list'));
    }

    public function test_the_release_event_is_dispatched_only_after_the_transaction_commits(): void
    {
        $dispatched = [];
        Event::listen(AppointmentSlotReleased::class, function (AppointmentSlotReleased $event) use (&$dispatched): void {
            $dispatched[] = $event->reason;
        });
        $slot = $this->slot($this->doctorA, '2026-11-26 10:00', 'blocked');

        try {
            DB::transaction(function () use ($slot, &$dispatched): void {
                app(AppointmentSlotRelease::class)->release($slot, AppointmentSlotRelease::REASON_UNBLOCKED);
                $this->assertSame([], $dispatched, 'Not dispatched before commit.');
                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame([], $dispatched, 'A rolled-back release dispatches nothing.');
        $this->assertSame('blocked', $slot->fresh()->status);

        DB::transaction(fn () => app(AppointmentSlotRelease::class)->release($slot, AppointmentSlotRelease::REASON_UNBLOCKED));
        $this->assertSame([AppointmentSlotRelease::REASON_UNBLOCKED], $dispatched);
    }

    public function test_concurrent_bookings_are_serialised_per_patient_and_per_slot(): void
    {
        $slot = $this->slot($this->doctorA, '2026-11-21 10:00');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        app(AppointmentBookingService::class)->book($this->patient, $slot->id, 'physical');

        $patientLock = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from "users"'));
        $slotLock = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from "appointment_slots"'));
        $this->assertNotFalse($patientLock);
        $this->assertLessThan($slotLock, $patientLock, 'The patient row is locked before the slot, so one patient\'s requests are checked one at a time.');

        try {
            app(AppointmentBookingService::class)->book(User::factory()->create(['role' => 'patient']), $slot->id, 'physical');
            $this->fail('A booked slot cannot be booked by someone else.');
        } catch (ValidationException) {
        }

        $this->expectException(UniqueConstraintViolationException::class);
        Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => User::factory()->create(['role' => 'patient'])->id, 'status' => 'booked', 'consultation_type' => 'physical']);
    }

    public function test_the_database_lets_a_new_request_reuse_the_slot_of_an_expired_one_only(): void
    {
        $expired = $this->appointment($this->doctorA, '2026-11-21 10:00', 'expired');
        $other = User::factory()->create(['role' => 'patient']);

        $reused = Appointment::create(['appointment_slot_id' => $expired->appointment_slot_id, 'patient_id' => $other->id, 'status' => 'booked', 'consultation_type' => 'physical']);
        $this->assertSame($expired->appointment_slot_id, $reused->fresh()->active_slot_id);

        $this->expectException(UniqueConstraintViolationException::class);
        Appointment::create(['appointment_slot_id' => $expired->appointment_slot_id, 'patient_id' => $this->patient->id, 'status' => 'confirmed', 'consultation_type' => 'physical']);
    }

    private function doctor(string $name): DoctorProfile
    {
        $user = User::factory()->create(['role' => 'doctor', 'name' => $name]);

        return DoctorProfile::create(['user_id' => $user->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$user->id, 'consultation_fee' => 10, 'is_accepting_patients' => true])->load('user');
    }

    private function slot(DoctorProfile $doctor, string $dhakaStart, string $status = 'available', int $secondOffset = 0): AppointmentSlot
    {
        $startsAt = CarbonImmutable::parse($dhakaStart, self::DHAKA)->addSeconds($secondOffset);

        return AppointmentSlot::create(['doctor_profile_id' => $doctor->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes(10), 'status' => $status]);
    }

    private function appointment(DoctorProfile $doctor, string $dhakaStart, string $status, int $secondOffset = 0): Appointment
    {
        $slot = $this->slot($doctor, $dhakaStart, in_array($status, Appointment::ACTIVE_STATUSES, true) || $status === 'completed' ? 'booked' : 'available', $secondOffset);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $this->patient->id, 'status' => $status, 'consultation_type' => 'physical', 'booked_at' => now()])->load('slot');
    }

    private function book(AppointmentSlot $slot): TestResponse
    {
        return $this->actingAs($this->patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical']);
    }
}
