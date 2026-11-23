<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentRescheduleHistory;
use App\Models\AppointmentSlot;
use App\Models\AssistantAssignmentEvent;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\DoctorAppointmentService;
use App\Services\DoctorAvailabilityService;
use App\Services\DoctorSlotGenerator;
use App\Services\DoctorWorkspaceEventService;
use App\Services\SlotBlockingService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\Concerns\CreatesDoctorLocations;
use Tests\TestCase;

class DoctorWorkspaceEventTest extends TestCase
{
    use CreatesDoctorLocations, RefreshDatabase;

    private User $doctor;

    private DoctorProfile $profile;

    private User $otherDoctor;

    private DoctorProfile $otherProfile;

    private User $assistant;

    private User $patient;

    private int $slotOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        [$this->doctor, $this->profile] = $this->doctor('Dr. Audit');
        [$this->otherDoctor, $this->otherProfile] = $this->doctor('Dr. Other');
        $this->assistant = User::factory()->create(['name' => 'Asha Assistant']);
        $this->assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $this->profile->assistants()->attach($this->assistant->id);
        $this->patient = User::factory()->create(['role' => 'patient', 'name' => 'Private Patient Name', 'email' => 'private.patient@example.com']);
    }

    public function test_patient_booking_and_cancellation_record_the_patient_as_actor(): void
    {
        config(['booking.remote_consultations_enabled' => true]);

        $slot = $this->slot($this->profile, daysAhead: 3);

        $this->actingAs($this->patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'video'])->assertRedirect();
        $appointment = Appointment::sole();
        $this->actingAs($this->patient)->post(route('appointments.cancel', $appointment))->assertRedirect();

        [$booked, $cancelled] = DoctorWorkspaceEvent::orderBy('id')->get()->all();
        $this->assertEvent($booked, 'booked', $this->patient, 'patient', $this->profile, $appointment, null, 'booked');
        $this->assertSame(['source' => 'patient', 'slot_id' => $slot->id, 'starts_at' => $slot->starts_at->toIso8601String(), 'consultation_type' => 'video'], $booked->context);
        $this->assertSame('127.0.0.1', $booked->ip_address, 'IP recorded for web requests.');
        $this->assertEvent($cancelled, 'cancelled', $this->patient, 'patient', $this->profile, $appointment, 'booked', 'cancelled');
        $this->assertSame(['appointment', $appointment->id], [$cancelled->subject_type, $cancelled->subject_id]);
    }

    public function test_doctor_appointment_actions_record_the_doctor(): void
    {
        $toConfirm = $this->appointment($this->profile, 'booked');
        $toReject = $this->appointment($this->profile, 'booked');
        $toReschedule = $this->appointment($this->profile, 'confirmed');
        $toComplete = $this->appointment($this->profile, 'confirmed');
        $toStart = $this->appointment($this->profile, 'confirmed', startsAt: now()->addMinutes(5));
        $newSlot = $this->slot($this->profile, daysAhead: 6);

        $this->actingAs($this->doctor)->post(route('doctor.appointments.confirm', $toConfirm))->assertRedirect();
        $this->actingAs($this->doctor)->post(route('doctor.appointments.reject', $toReject))->assertRedirect();
        $this->actingAs($this->doctor)->post(route('doctor.appointments.reschedule.store', $toReschedule), ['slot_id' => $newSlot->id, 'reason' => 'Patient has a high fever'])->assertRedirect();
        $this->actingAs($this->doctor)->post(route('doctor.appointments.complete', $toComplete))->assertRedirect();
        $this->actingAs($this->doctor)->post(route('doctor.appointments.start', $toStart))->assertRedirect()->assertSessionHasNoErrors();

        $events = DoctorWorkspaceEvent::orderBy('id')->get();
        $this->assertSame(['confirmed', 'rejected', 'rescheduled', 'completed', 'started'], $events->pluck('action')->all());
        $this->assertTrue($events->every(fn ($event) => $event->actor_id === $this->doctor->id && $event->actor_role === 'doctor' && $event->doctor_profile_id === $this->profile->id));
        $this->assertSame(['booked', 'confirmed'], [$events[0]->from_status, $events[0]->to_status]);
        $this->assertSame(['booked', 'rejected'], [$events[1]->from_status, $events[1]->to_status]);
        $this->assertSame(['confirmed', 'completed'], [$events[3]->from_status, $events[3]->to_status]);

        $history = AppointmentRescheduleHistory::sole();
        $this->assertSame($this->doctor->id, $history->performed_by, 'Reschedule history is kept as before.');
        $this->assertSame([
            'previous_slot_id' => $history->previous_slot_id,
            'new_slot_id' => $newSlot->id,
            'previous_starts_at' => CarbonImmutable::parse($history->previous_starts_at)->toIso8601String(),
            'new_starts_at' => $newSlot->starts_at->toIso8601String(),
            'reschedule_history_id' => $history->id,
        ], $events[2]->context);
        $this->assertStringNotContainsString('fever', json_encode($events->pluck('context')));
    }

    public function test_assistant_actions_record_the_assistant_and_the_assigned_doctor(): void
    {
        $appointment = $this->appointment($this->profile, 'booked');
        $free = $this->slot($this->profile);
        $toBlock = $this->slot($this->profile);

        app(DoctorAppointmentService::class)->transition($this->assistant, $appointment, 'confirmed', $this->profile);
        app(DoctorAppointmentService::class)->reschedule($this->assistant, $appointment->fresh(), $this->slot($this->profile, daysAhead: 7)->id, 'Doctor away', $this->profile);
        app(AppointmentBookingService::class)->bookForDoctor($this->assistant, $this->patient, $free->id, 'physical', 'Bring earlier X-rays', $this->profile);
        app(SlotBlockingService::class)->block($this->assistant, $toBlock, $this->profile);
        app(SlotBlockingService::class)->unblock($this->assistant, $toBlock, $this->profile);
        $availability = app(DoctorAvailabilityService::class)->create($this->assistant, $this->profile, ['doctor_location_id' => $this->locationFor($this->profile)->id, 'day_of_week' => 2, 'start_time' => '09:00', 'end_time' => '11:00'], true);
        app(DoctorAvailabilityService::class)->update($this->assistant, $availability, ['day_of_week' => 2, 'start_time' => '10:00', 'end_time' => '11:00'], false, $this->profile);
        app(DoctorAvailabilityService::class)->delete($this->assistant, $availability, $this->profile);
        $this->weeklyAvailability($this->profile);
        app(DoctorSlotGenerator::class)->generateRange($this->profile, CarbonImmutable::now()->addDays(20), CarbonImmutable::now()->addDays(26), [1, 3], $this->assistant);

        $events = DoctorWorkspaceEvent::orderBy('id')->get();
        $this->assertSame(['confirmed', 'rescheduled', 'booked', 'slot_blocked', 'slot_unblocked', 'availability_created', 'availability_updated', 'availability_deleted', 'slots_generated'], $events->pluck('action')->all());
        $this->assertTrue($events->every(fn ($event) => $event->actor_id === $this->assistant->id && $event->actor_role === 'assistant' && $event->doctor_profile_id === $this->profile->id), 'Never the doctor\'s id.');
        $this->assertSame(0, DoctorWorkspaceEvent::where('actor_id', $this->doctor->id)->count());
        $this->assertSame($this->assistant->id, AppointmentRescheduleHistory::sole()->performed_by, 'Reschedule history also records the assistant.');
        $this->assertSame(AppointmentRescheduleHistory::sole()->id, $events[1]->context['reschedule_history_id']);
        $this->assertSame('staff', $events[2]->context['source']);
        $this->assertSame(['appointment_slot', $toBlock->id], [$events[3]->subject_type, $events[3]->subject_id]);
        $this->assertSame(['start_time' => ['09:00', '10:00'], 'is_active' => [true, false]], $events[6]->context['changes']);
        $this->assertSame(['doctor_availability', $availability->id], [$events[7]->subject_type, $events[7]->subject_id]);
        $this->assertGreaterThan(0, $events[8]->context['slot_count']);
        $this->assertNull($events[0]->ip_address, 'No IP outside web requests.');
        $this->assertStringNotContainsString('X-rays', json_encode($events->pluck('context')));
        $this->assertStringNotContainsString('Doctor away', json_encode($events->pluck('context')));
    }

    public function test_doctor_slot_and_availability_changes_through_the_existing_routes(): void
    {
        $this->weeklyAvailability($this->profile);
        $this->actingAs($this->doctor)->post(route('doctor.slots.generate'), ['start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(16)->toDateString(), 'weekdays' => [1, 2]])->assertSessionHasNoErrors();
        $created = AppointmentSlot::count();
        $this->assertGreaterThan(0, $created);
        DB::table('doctor_availabilities')->delete(); // Clear the setup rows so the availability created below is the only one.
        $this->actingAs($this->doctor)->post(route('doctor.slots.generate'), ['start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(16)->toDateString(), 'weekdays' => [1, 2]])->assertSessionHasNoErrors();
        $this->actingAs($this->doctor)->post(route('doctor.availability.store'), ['doctor_location_id' => $this->locationFor($this->profile)->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '10:00', 'is_active' => '1'])->assertSessionHasNoErrors();
        $availability = DoctorAvailability::sole();
        $this->actingAs($this->doctor)->patch(route('doctor.availability.update', $availability), ['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00', 'is_active' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($this->doctor)->delete(route('doctor.availability.destroy', $availability))->assertSessionHasNoErrors();

        $events = DoctorWorkspaceEvent::orderBy('id')->get();
        $this->assertSame(['slots_generated', 'availability_created', 'availability_updated', 'availability_deleted'], $events->pluck('action')->all(), 'A run that creates no slots records nothing.');
        $this->assertSame($created, $events[0]->context['slot_count']);
        $this->assertSame([1, 2], $events[0]->context['weekdays']);
        $this->assertSame(['end_time' => ['10:00', '11:00']], $events[2]->context['changes']);
        $this->assertModelMissing($availability);
        $this->assertSame($availability->id, $events[3]->subject_id, 'The event survives the deleted availability.');
        $this->assertTrue($events->every(fn ($event) => $event->actor_id === $this->doctor->id && $event->actor_role === 'doctor'));
    }

    public function test_system_generated_slots_use_the_system_actor(): void
    {
        $this->weeklyAvailability($this->profile);
        app(DoctorSlotGenerator::class)->generateRange($this->profile, CarbonImmutable::now()->addDays(5), CarbonImmutable::now()->addDays(5), [CarbonImmutable::now()->addDays(5)->dayOfWeekIso]);

        $event = DoctorWorkspaceEvent::sole();
        $this->assertNull($event->actor_id);
        $this->assertSame('system', $event->actor_role);
        $this->assertSame($this->profile->id, $event->doctor_profile_id);
        $this->assertNull($event->ip_address);
    }

    public function test_failed_operations_leave_no_event(): void
    {
        $confirmed = $this->appointment($this->profile, 'confirmed');
        $bookedSlot = $this->appointment($this->profile, 'booked')->slot;
        $taken = $this->slot($this->profile, status: 'booked');
        $attempts = [
            'confirm an already confirmed appointment' => fn () => app(DoctorAppointmentService::class)->transition($this->doctor, $confirmed, 'confirmed'),
            'complete a booked appointment' => fn () => app(DoctorAppointmentService::class)->complete($this->doctor, Appointment::where('status', 'booked')->first()),
            'book an unavailable slot' => fn () => app(AppointmentBookingService::class)->book($this->patient, $taken->id, 'physical'),
            'block a slot with an active appointment' => fn () => app(SlotBlockingService::class)->block($this->doctor, $bookedSlot),
            'unblock a slot that is not blocked' => fn () => app(SlotBlockingService::class)->unblock($this->doctor, $this->slot($this->profile)),
            'generate with invalid weekdays' => fn () => app(DoctorSlotGenerator::class)->generateRange($this->profile, CarbonImmutable::now()->addDay(), CarbonImmutable::now()->addDays(2), [9], $this->doctor),
        ];

        foreach ($attempts as $case => $attempt) {
            try {
                $attempt();
                $this->fail("{$case} must fail.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->actingAs($this->doctor)->post(route('doctor.availability.store'), ['doctor_location_id' => $this->locationFor($this->profile)->id, 'day_of_week' => 1, 'start_time' => '11:00', 'end_time' => '10:00'])->assertSessionHasErrors();
        $this->assertSame(0, DoctorWorkspaceEvent::count());
    }

    public function test_an_event_is_rolled_back_with_its_operation_and_the_operation_with_its_event(): void
    {
        $appointment = $this->appointment($this->profile, 'booked');
        $listener = function (string $table) {
            return function ($query) use ($table): void {
                if (str_starts_with(strtolower($query->sql), "insert into \"{$table}\"")) {
                    throw new RuntimeException("Simulated failure writing {$table}.");
                }
            };
        };

        DB::listen($listener('appointment_notifications'));
        try {
            app(DoctorAppointmentService::class)->transition($this->doctor, $appointment, 'confirmed');
            $this->fail('The simulated failure must surface.');
        } catch (RuntimeException) {
        }
        $this->assertSame('booked', $appointment->fresh()->status);
        $this->assertSame(0, DoctorWorkspaceEvent::count(), 'No event remains for a rolled-back confirmation.');
    }

    public function test_a_failure_while_writing_the_event_rolls_back_the_operation(): void
    {
        $slot = $this->slot($this->profile);
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "doctor_workspace_events"')) {
                throw new RuntimeException('Simulated audit failure.');
            }
        });

        try {
            app(SlotBlockingService::class)->block($this->doctor, $slot);
            $this->fail('The simulated failure must surface.');
        } catch (RuntimeException) {
        }

        $this->assertSame('available', $slot->fresh()->status, 'The block is undone when its audit event cannot be written.');
    }

    public function test_events_are_append_only_and_not_mass_assignable(): void
    {
        $this->actingAs($this->doctor)->post(route('doctor.slots.block', $slot = $this->slot($this->profile)));
        $event = DoctorWorkspaceEvent::sole();

        foreach ([fn () => $event->forceFill(['action' => 'slot_unblocked'])->save(), fn () => $event->delete()] as $change) {
            try {
                $change();
                $this->fail('Audit events must not change.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('slot_blocked', $event->fresh()->action);

        $this->expectException(MassAssignmentException::class);
        DoctorWorkspaceEvent::create(['doctor_profile_id' => $this->profile->id, 'actor_role' => 'admin', 'action' => 'booked']);
    }

    public function test_history_survives_deleting_the_appointment_or_the_actor_but_blocks_deleting_the_doctor(): void
    {
        $appointment = $this->appointment($this->profile, 'booked');
        app(DoctorAppointmentService::class)->transition($this->assistant, $appointment, 'confirmed', $this->profile);
        $event = DoctorWorkspaceEvent::sole();

        $appointment->delete();
        $this->assertNull($event->fresh()->appointment_id);
        $this->assertSame($appointment->id, $event->fresh()->subject_id, 'The subject reference is kept.');

        $this->assistant->delete();
        $this->assertNull($event->fresh()->actor_id);
        $this->assertSame('assistant', $event->fresh()->actor_role);

        $this->expectException(QueryException::class);
        $this->profile->delete();
    }

    public function test_events_cannot_be_recorded_for_the_wrong_doctor_actor_or_role(): void
    {
        $service = app(DoctorWorkspaceEventService::class);
        $mine = $this->appointment($this->profile, 'booked');
        $theirs = $this->appointment($this->otherProfile, 'booked');
        $someoneElse = User::factory()->create(['role' => 'patient']);
        $forged = User::find($this->patient->id);
        $forged->role = 'doctor';
        $refused = [
            'assistant for an unassigned doctor' => [AuthorizationException::class, fn () => $service->record($this->assistant, $this->otherProfile, 'confirmed', appointment: $theirs)],
            'assistant through a service for an unassigned doctor' => [AuthorizationException::class, fn () => app(DoctorAppointmentService::class)->transition($this->assistant, $theirs, 'confirmed', $this->otherProfile)],
            'doctor for another doctor' => [AuthorizationException::class, fn () => $service->record($this->doctor, $this->otherProfile, 'confirmed', appointment: $theirs)],
            'admin' => [AuthorizationException::class, fn () => $service->record(User::factory()->create(['role' => 'admin']), $this->profile, 'confirmed', appointment: $mine)],
            'patient recording a staff action' => [AuthorizationException::class, fn () => $service->record($this->patient, $this->profile, 'confirmed', appointment: $mine)],
            'patient recording another patient\'s booking' => [AuthorizationException::class, fn () => $service->record($someoneElse, $this->profile, 'booked', appointment: $mine)],
            'forged in-memory role' => [AuthorizationException::class, fn () => $service->record($forged, $this->profile, 'confirmed', appointment: $mine)],
            'raw id of another doctor for this appointment' => [LogicException::class, fn () => $service->record($this->doctor, $this->otherProfile->id, 'confirmed', appointment: $mine)],
            'unknown doctor id' => [LogicException::class, fn () => $service->record($this->doctor, 999999, 'confirmed', appointment: $mine)],
            'unknown action' => [LogicException::class, fn () => $service->record($this->doctor, $this->profile, 'deleted_everything', appointment: $mine)],
            'appointment action without appointment' => [LogicException::class, fn () => $service->record($this->doctor, $this->profile, 'confirmed')],
            'another doctor\'s slot as subject' => [LogicException::class, fn () => $service->record($this->doctor, $this->profile, 'slot_blocked', subject: $this->slot($this->otherProfile))],
            'actor id smuggled in context' => [LogicException::class, fn () => $service->record($this->doctor, $this->profile, 'slot_blocked', subject: $this->slot($this->profile), context: ['actor_id' => $this->otherDoctor->id])],
        ];

        foreach ($refused as $case => [$exception, $attempt]) {
            try {
                DB::transaction($attempt);
                $this->fail("{$case} must be refused.");
            } catch (AuthorizationException|LogicException $caught) {
                $this->assertInstanceOf($exception, $caught, $case);
            }
        }

        $this->assertSame(0, DoctorWorkspaceEvent::count());
        $this->assertSame('booked', $theirs->fresh()->status);
    }

    public function test_context_rejects_sensitive_or_unexpected_data_and_events_need_a_transaction(): void
    {
        $service = app(DoctorWorkspaceEventService::class);
        $slot = $this->slot($this->profile);

        foreach ([
            ['reason' => 'Patient has a high fever'],
            ['patient_notes' => 'Allergic to penicillin'],
            ['email' => 'private.patient@example.com'],
            ['token' => 'secret'],
            ['starts_at' => ['nested' => ['too' => 'deep']]],
            ['changes' => ['description' => ['a', 'b']]],
        ] as $context) {
            try {
                DB::transaction(fn () => $service->record($this->doctor, $this->profile, 'slot_blocked', subject: $slot, context: $context));
                $this->fail('Context '.json_encode(array_keys($context)).' must be refused.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        DB::rollBack();
        try {
            $service->record($this->doctor, $this->profile, 'slot_blocked', subject: $slot);
            $this->fail('Recording outside a transaction must be refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('inside the transaction', $exception->getMessage());
        } finally {
            DB::beginTransaction();
        }
        $this->assertSame(0, DoctorWorkspaceEvent::count());
    }

    public function test_no_sensitive_patient_data_is_stored_and_no_route_exposes_events(): void
    {
        $slot = $this->slot($this->profile, daysAhead: 3);
        $this->actingAs($this->patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical']);
        Appointment::sole()->update(['patient_notes' => 'Chest pain since Monday']);
        $this->actingAs($this->doctor)->post(route('doctor.appointments.confirm', Appointment::sole()));

        $stored = json_encode(DB::table('doctor_workspace_events')->get());
        foreach (['Private Patient Name', 'private.patient@example.com', 'Chest pain', $this->patient->password] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $stored);
        }

        $routes = collect(app('router')->getRoutes())->map(fn ($route) => $route->uri());
        $this->assertTrue($routes->every(fn (string $uri) => ! str_contains($uri, 'workspace-event') && ! str_contains($uri, 'audit')));
    }

    public function test_existing_assignment_history_and_reschedule_history_are_untouched(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $event = new AssistantAssignmentEvent;
        $event->forceFill(['assistant_id' => $this->assistant->id, 'doctor_profile_id' => $this->profile->id, 'action' => 'assigned', 'actor_id' => $admin->id])->save();
        $appointment = $this->appointment($this->profile, 'confirmed');

        app(DoctorAppointmentService::class)->reschedule($this->doctor, $appointment, $this->slot($this->profile, daysAhead: 8)->id);

        $this->assertSame(1, AssistantAssignmentEvent::count());
        $this->assertSame(1, AppointmentRescheduleHistory::count());
        $this->assertSame(1, DoctorWorkspaceEvent::where('action', 'rescheduled')->count());
    }

    /**
     * Weekly availability on every day, written directly (no audit event) so slot generation has hours to fill.
     */
    private function weeklyAvailability(DoctorProfile $profile): void
    {
        foreach (range(0, 6) as $day) {
            DB::table('doctor_availabilities')->insert(['doctor_profile_id' => $profile->id, 'day_of_week' => $day, 'start_time' => '09:00', 'end_time' => '10:00', 'slot_duration_minutes' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function assertEvent(DoctorWorkspaceEvent $event, string $action, ?User $actor, string $role, DoctorProfile $profile, ?Appointment $appointment, ?string $from, ?string $to): void
    {
        $this->assertSame($action, $event->action);
        $this->assertSame($actor?->id, $event->actor_id);
        $this->assertSame($role, $event->actor_role);
        $this->assertSame($profile->id, $event->doctor_profile_id);
        $this->assertSame($appointment?->id, $event->appointment_id);
        $this->assertSame([$from, $to], [$event->from_status, $event->to_status]);
    }

    /**
     * @return array{0: User, 1: DoctorProfile}
     */
    private function doctor(string $name): array
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name]);
        $profile = DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        return [$doctor, $profile];
    }

    private function slot(DoctorProfile $profile, int $daysAhead = 4, string $status = 'available', mixed $startsAt = null): AppointmentSlot
    {
        $startsAt = $startsAt ?? now()->startOfHour()->addDays($daysAhead)->addMinutes(10 * $this->slotOffset++);

        return AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(10), 'status' => $status]);
    }

    private function appointment(DoctorProfile $profile, string $status, mixed $startsAt = null): Appointment
    {
        $slot = $this->slot($profile, status: 'booked', startsAt: $startsAt);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $this->patient->id, 'status' => $status, 'consultation_type' => 'physical']);
    }
}
