<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use App\Services\DoctorAppointmentService;
use App\Services\Sms\FakeSmsGateway;
use App\Services\Sms\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\AssertsUnavailableSlotRedirect;
use Tests\TestCase;

class StaffAppointmentCancellationTest extends TestCase
{
    use AssertsUnavailableSlotRedirect, RefreshDatabase;

    private const REASON = 'Doctor called away for an emergency surgery';

    private FakeSmsGateway $sms;

    private User $doctorA;

    private DoctorProfile $profileA;

    private User $doctorB;

    private DoctorProfile $profileB;

    private User $doctorC;

    private DoctorProfile $profileC;

    private User $assistant;

    private User $patient;

    private int $slotOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        $this->sms = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
        [$this->doctorA, $this->profileA] = $this->doctor('Dr. Alpha', '01811111111');
        [$this->doctorB, $this->profileB] = $this->doctor('Dr. Beta');
        [$this->doctorC, $this->profileC] = $this->doctor('Dr. Gamma');
        $this->assistant = User::factory()->create(['name' => 'Asha Assistant', 'phone' => '01922222222', 'sms_notifications_enabled' => true]);
        $this->assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $this->profileA->assistants()->attach($this->assistant->id);
        $this->profileB->assistants()->attach($this->assistant->id);
        $this->patient = User::factory()->create(['role' => 'patient', 'name' => 'Pat Patient', 'phone' => '01712345678', 'sms_notifications_enabled' => true]);
    }

    public function test_a_doctor_cancels_with_a_reason_and_everyone_is_told_once(): void
    {
        $appointment = $this->appointment($this->profileA, 'booked');

        $this->actingAs($this->doctorA)->post(route('doctor.appointments.cancel', $appointment), ['reason' => '  '.self::REASON.'  '])
            ->assertRedirect(route('doctor.appointments.show', $appointment))
            ->assertSessionHas('status', 'Appointment cancelled. The patient has been notified.');

        $appointment->refresh();
        $this->assertSame('cancelled', $appointment->status);
        $this->assertSame(self::REASON, $appointment->cancellation_reason, 'Stored trimmed.');
        $this->assertNotNull($appointment->cancelled_at);
        $this->assertSame('available', $appointment->slot->status);

        $event = DoctorWorkspaceEvent::sole();
        $this->assertSame(['cancelled', $this->doctorA->id, 'doctor', $this->profileA->id, $appointment->id, 'booked', 'cancelled'],
            [$event->action, $event->actor_id, $event->actor_role, $event->doctor_profile_id, $event->appointment_id, $event->from_status, $event->to_status]);
        $this->assertSame(['source' => 'staff', 'slot_id' => $appointment->appointment_slot_id, 'starts_at' => $appointment->slot->starts_at->toIso8601String(), 'slot_released' => true], $event->context);
        $this->assertStringNotContainsString('emergency', json_encode(DB::table('doctor_workspace_events')->get()));

        $emails = $this->sentEmails();
        $this->assertSame([$this->patient->email, $this->doctorA->email], $emails->map(fn ($email) => $email->getTo()[0]->getAddress())->all());
        $this->assertStringStartsWith('Your appointment has been cancelled', $emails[0]->getSubject());
        $this->assertStringContainsString('cancelled by the clinic', $emails[0]->getTextBody());
        $this->assertStringContainsString(route('doctors.show', $this->profileA), $emails[0]->getTextBody());
        $this->assertStringContainsString('You cancelled', $emails[1]->getTextBody());
        foreach ($emails as $email) {
            $this->assertStringNotContainsString('emergency', $email->getTextBody().$email->getHtmlBody(), 'The reason is never emailed.');
        }

        $this->assertCount(1, $this->sms->sentTo('8801712345678'));
        $this->assertStringContainsString('has been cancelled by the clinic', $this->sms->sentTo('8801712345678')[0]['message']);
        $this->assertSame([], $this->sms->sentTo('8801811111111'), 'The doctor did not opt in to SMS.');
        $this->assertSame(0, AppointmentNotification::where('type', 'cancelled')->count(), 'Not the patient-cancellation notice.');
        Queue::assertPushed(SyncAppointmentToGoogleCalendar::class, fn ($job) => $job->operation === 'delete');
    }

    public function test_an_assigned_assistant_cancels_for_several_doctors_as_themselves(): void
    {
        $this->doctorA->forceFill(['sms_notifications_enabled' => true])->save();
        $forA = $this->appointment($this->profileA, 'confirmed');
        $forB = $this->appointment($this->profileB, 'booked');

        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.cancel', [$this->profileA, $forA]), ['reason' => self::REASON])
            ->assertRedirect(route('assistant.workspace.appointments.show', [$this->profileA, $forA]));
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.cancel', [$this->profileB, $forB]), ['reason' => 'Clinic closed'])
            ->assertRedirect();

        $this->assertSame(['cancelled', 'cancelled'], [$forA->fresh()->status, $forB->fresh()->status]);
        $events = DoctorWorkspaceEvent::orderBy('id')->get();
        $this->assertTrue($events->every(fn ($event) => $event->actor_id === $this->assistant->id && $event->actor_role === 'assistant'));
        $this->assertSame([$this->profileA->id, $this->profileB->id], $events->pluck('doctor_profile_id')->all());
        $this->assertSame(['confirmed', 'booked'], $events->pluck('from_status')->all());

        $doctorEmail = $this->sentEmails()->first(fn ($email) => $email->getTo()[0]->getAddress() === $this->doctorA->email);
        $this->assertStringContainsString('Your assistant cancelled', $doctorEmail->getTextBody());
        $this->assertStringContainsString('cancelled by your assistant', $this->sms->sentTo('8801811111111')[0]['message']);
        $this->assertSame(0, AppointmentNotification::where('recipient_id', $this->assistant->id)->count());
        $this->assertSame([], $this->sms->sentTo('8801922222222'), 'The assistant is never a recipient.');
        $this->assertFalse($this->sentEmails()->contains(fn ($email) => $email->getTo()[0]->getAddress() === $this->assistant->email));
    }

    public function test_cancellation_is_refused_without_current_authority(): void
    {
        $forC = $this->appointment($this->profileC, 'booked');
        $forB = $this->appointment($this->profileB, 'booked');
        $forA = $this->appointment($this->profileA, 'booked');
        $payload = ['reason' => self::REASON];

        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.cancel', [$this->profileC, $forC]), $payload)->assertForbidden();
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.cancel', [$this->profileA, $forC]), $payload)->assertForbidden();
        $this->actingAs($this->doctorA)->post(route('doctor.appointments.cancel', $forB), $payload)->assertForbidden();
        $this->actingAs($this->patient)->post(route('doctor.appointments.cancel', $forA), $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('doctor.appointments.cancel', $forA), $payload)->assertForbidden();

        $this->profileB->assistants()->detach($this->assistant->id);
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.cancel', [$this->profileB, $forB]), $payload)->assertForbidden();
        $this->doctorA->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($this->assistant)->post(route('assistant.workspace.appointments.cancel', [$this->profileA, $forA]), $payload)->assertForbidden();
        $this->doctorA->forceFill(['deactivated_at' => null])->save();
        $this->assistant->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($this->assistant->fresh())->post(route('assistant.workspace.appointments.cancel', [$this->profileA, $forA]), $payload)->assertForbidden();

        foreach ([$forA, $forB, $forC] as $appointment) {
            $this->assertSame('booked', $appointment->fresh()->status);
        }
        $this->assertNothingRecorded();
    }

    public function test_a_reason_is_required_and_only_booked_or_confirmed_appointments_can_be_cancelled(): void
    {
        $booked = $this->appointment($this->profileA, 'booked');

        foreach (['' => 'Enter the reason for cancelling this appointment.', '     ' => 'Enter the reason for cancelling this appointment.', str_repeat('x', 1001) => 'The cancellation reason may not be longer than 1000 characters.'] as $reason => $message) {
            $this->actingAs($this->doctorA)->post(route('doctor.appointments.cancel', $booked), ['reason' => $reason])->assertSessionHasErrors(['reason' => $message]);
        }
        $this->actingAs($this->doctorA)->post(route('doctor.appointments.cancel', $booked), [])->assertSessionHasErrors('reason');

        foreach (['completed', 'rejected', 'cancelled'] as $status) {
            $appointment = $this->appointment($this->profileA, $status);
            $this->actingAs($this->doctorA)->post(route('doctor.appointments.cancel', $appointment), ['reason' => self::REASON])
                ->assertSessionHasErrors(['status' => 'Only booked or confirmed appointments can be cancelled.']);
            $this->assertSame($status, $appointment->fresh()->status);
        }

        $this->expectException(ValidationException::class);
        try {
            app(DoctorAppointmentService::class)->cancel($this->doctorA, $booked, "  \n\t ", $this->profileA);
        } finally {
            $this->assertSame('booked', $booked->fresh()->status);
            $this->assertNothingRecorded();
        }
    }

    public function test_repeated_or_concurrent_cancellation_happens_once(): void
    {
        $appointment = $this->appointment($this->profileA, 'confirmed');
        $staleCopy = Appointment::find($appointment->id);

        app(DoctorAppointmentService::class)->cancel($this->doctorA, $appointment, self::REASON, $this->profileA);
        $rows = AppointmentNotification::count();

        foreach ([fn () => app(DoctorAppointmentService::class)->cancel($this->assistant, $staleCopy, 'Second attempt', $this->profileA),
            fn () => $this->actingAs($this->doctorA)->post(route('doctor.appointments.cancel', $appointment), ['reason' => 'Again'])->assertSessionHasErrors('status')] as $attempt) {
            try {
                $attempt();
            } catch (ValidationException) {
            }
        }

        $this->assertSame(1, DoctorWorkspaceEvent::where('action', 'cancelled')->count());
        $this->assertSame($rows, AppointmentNotification::count(), 'No duplicate notifications.');
        $this->assertSame(self::REASON, $appointment->fresh()->cancellation_reason, 'The first reason is kept.');
        $this->assertSame(1, AppointmentSlot::where('id', $appointment->appointment_slot_id)->where('status', 'available')->count());
    }

    public function test_slot_release_rules_and_rebooking_the_released_slot(): void
    {
        $appointment = $this->appointment($this->profileA, 'booked', daysAhead: 3);
        $blocked = $this->appointment($this->profileA, 'booked', daysAhead: 4);
        $blocked->slot->forceFill(['status' => 'blocked'])->save();
        $otherDoctorsSlot = $this->slot($this->profileB, 'available');
        $slotCount = AppointmentSlot::count();

        app(DoctorAppointmentService::class)->cancel($this->doctorA, $appointment, self::REASON, $this->profileA);
        app(DoctorAppointmentService::class)->cancel($this->doctorA, $blocked, self::REASON, $this->profileA);

        $this->assertSame('available', $appointment->slot->fresh()->status);
        $this->assertSame('blocked', $blocked->slot->fresh()->status, 'A blocked slot stays blocked.');
        $this->assertFalse(DoctorWorkspaceEvent::where('appointment_id', $blocked->id)->sole()->context['slot_released']);
        $this->assertSame('available', $otherDoctorsSlot->fresh()->status);
        $this->assertSame($slotCount, AppointmentSlot::count(), 'No slots are created.');

        $newPatient = User::factory()->create(['role' => 'patient']);
        $this->actingAs($newPatient)->post(route('appointment-slots.book', $appointment->slot), ['consultation_type' => 'physical'])->assertRedirect();
        $this->assertSame('booked', $appointment->slot->fresh()->status);
        $this->assertSame(2, Appointment::where('appointment_slot_id', $appointment->appointment_slot_id)->count());
        $this->assertSame(1, Appointment::where('appointment_slot_id', $appointment->appointment_slot_id)->whereIn('status', ['booked', 'confirmed'])->count());
        $this->assertSentToChooseAnotherTime($this->actingAs(User::factory()->create(['role' => 'patient']))->post(route('appointment-slots.book', $appointment->slot), ['consultation_type' => 'physical']), $appointment->slot);
    }

    public function test_a_failure_rolls_back_the_cancellation_its_event_and_its_notifications(): void
    {
        $appointment = $this->appointment($this->profileA, 'booked');
        DB::listen(function ($query): void {
            if (str_starts_with(strtolower($query->sql), 'insert into "doctor_workspace_events"')) {
                throw new RuntimeException('Simulated audit failure.');
            }
        });

        try {
            app(DoctorAppointmentService::class)->cancel($this->doctorA, $appointment, self::REASON, $this->profileA);
            $this->fail('The simulated failure must surface.');
        } catch (RuntimeException) {
        }

        $this->assertSame('booked', $appointment->fresh()->status);
        $this->assertNull($appointment->fresh()->cancellation_reason);
        $this->assertSame('booked', $appointment->slot->fresh()->status);
        $this->assertNothingRecorded();
    }

    public function test_sms_respects_the_patient_preference_and_the_driver(): void
    {
        $optedOut = User::factory()->create(['role' => 'patient', 'phone' => '01799999999', 'sms_notifications_enabled' => false]);
        app(DoctorAppointmentService::class)->cancel($this->doctorA, $this->appointment($this->profileA, 'booked', patient: $optedOut), self::REASON, $this->profileA);
        $this->assertSame([], $this->sms->sent);
        $this->assertSame(1, AppointmentNotification::where('channel', 'email')->whereJsonContains('context->audience', 'patient')->count(), 'Email is still sent.');

        $this->app->instance(SmsGateway::class, $this->sms = new FakeSmsGateway(configured: false));
        $appointment = $this->appointment($this->profileA, 'booked');
        app(DoctorAppointmentService::class)->cancel($this->doctorA, $appointment, self::REASON, $this->profileA);
        $this->assertSame([], $this->sms->sent);
        $this->assertSame('No SMS provider is configured.', AppointmentNotification::where('appointment_id', $appointment->id)->where('channel', 'sms')->sole()->last_error);
    }

    public function test_the_cancel_dialog_appears_only_for_staff_who_may_cancel_eligible_appointments(): void
    {
        $booked = $this->appointment($this->profileA, 'booked');
        $completed = $this->appointment($this->profileA, 'completed');

        $this->actingAs($this->doctorA)->get(route('doctor.appointments.show', $booked))->assertOk()
            ->assertSee('data-bs-target="#cancel-appointment"', false)
            ->assertSee('action="'.route('doctor.appointments.cancel', $booked).'"', false)
            ->assertSee('name="reason"', false)->assertSee('It is not sent to the patient.');
        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.show', [$this->profileA, $booked]))->assertOk()
            ->assertSee('action="'.route('assistant.workspace.appointments.cancel', [$this->profileA, $booked]).'"', false);
        $this->actingAs($this->doctorA)->get(route('doctor.appointments.show', $completed))->assertOk()->assertDontSee('cancel-appointment');

        app(DoctorAppointmentService::class)->cancel($this->doctorA, $booked, self::REASON, $this->profileA);
        $this->actingAs($this->doctorA)->get(route('doctor.appointments.show', $booked))->assertOk()
            ->assertSee(self::REASON)->assertSee('Cancellation reason (staff only)')->assertDontSee('cancel-appointment');
        $this->flushSession();
        $this->actingAs($this->patient)->get(route('patient.appointments.show', $booked))->assertOk()->assertDontSee('emergency');
    }

    public function test_patient_cancellation_rules_are_unchanged(): void
    {
        $soon = $this->appointment($this->profileA, 'booked', startsAt: now()->addHours(12));
        $later = $this->appointment($this->profileA, 'booked', daysAhead: 3);

        $this->actingAs($this->patient)->post(route('appointments.cancel', $soon))->assertSessionHasErrors('appointment');
        $this->actingAs($this->patient)->post(route('appointments.cancel', $later))->assertRedirect();

        $this->assertSame('booked', $soon->fresh()->status);
        $this->assertSame('cancelled', $later->fresh()->status);
        $this->assertNull($later->fresh()->cancellation_reason);
        $this->assertSame(['cancelled', 'cancelled:patient'], AppointmentNotification::where('channel', 'email')->orderBy('id')->pluck('transition_key')->all(), 'Patient cancellations keep their own notice to the doctor, plus a receipt for the patient.');
        $this->assertEqualsCanonicalizing([$this->doctorA->email, $this->patient->email], $this->sentEmails()->map(fn ($email) => $email->getTo()[0]->getAddress())->all());
        $this->assertSame('patient', DoctorWorkspaceEvent::sole()->actor_role);
    }

    private function assertNothingRecorded(): void
    {
        $this->assertSame(0, DoctorWorkspaceEvent::count(), 'No audit event.');
        $this->assertSame(0, AppointmentNotification::count(), 'No notification.');
        $this->assertCount(0, $this->sentEmails());
        $this->assertSame([], $this->sms->sent);
    }

    private function sentEmails(): Collection
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->map(fn ($sent) => $sent->getOriginalMessage())->values();
    }

    /**
     * @return array{0: User, 1: DoctorProfile}
     */
    private function doctor(string $name, ?string $phone = null): array
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name, 'phone' => $phone]);
        $profile = DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        return [$doctor, $profile];
    }

    private function slot(DoctorProfile $profile, string $status, int $daysAhead = 2, mixed $startsAt = null): AppointmentSlot
    {
        $startsAt = $startsAt ?? now()->startOfHour()->addDays($daysAhead)->addMinutes(10 * $this->slotOffset++);

        return AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(10), 'status' => $status]);
    }

    private function appointment(DoctorProfile $profile, string $status, int $daysAhead = 2, ?User $patient = null, mixed $startsAt = null): Appointment
    {
        $slot = $this->slot($profile, in_array($status, ['cancelled', 'rejected'], true) ? 'available' : 'booked', $daysAhead, $startsAt);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => ($patient ?? $this->patient)->id, 'status' => $status, 'consultation_type' => 'physical']);
    }
}
