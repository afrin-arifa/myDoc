<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentNotification;
use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Notifications\AppointmentBookedNotification;
use App\Notifications\AppointmentCancelledNotification;
use App\Notifications\AppointmentConfirmedNotification;
use App\Notifications\AppointmentRejectedNotification;
use App\Notifications\AppointmentRescheduledNotification;
use App\Services\AppointmentBookingService;
use App\Services\DoctorAppointmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class AppointmentEmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    private int $slotOffset = 0;

    public function test_patient_booking_emails_the_doctor_with_scheduling_details_only(): void
    {
        $doctor = $this->doctor('Dr. Rowan Hale');
        $patient = $this->patient('Sam Patient');
        $slot = $this->slot($doctor);

        $this->actingAs($patient)
            ->post(route('appointment-slots.book', $slot), ['consultation_type' => 'video'])
            ->assertRedirect();

        $appointment = Appointment::firstOrFail();
        $appointment->update(['patient_notes' => 'Private symptom description']);
        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $email = $emails->first();
        $this->assertSame($doctor->email, $email->getTo()[0]->getAddress());
        $this->assertStringStartsWith('New appointment request', $email->getSubject());
        foreach ([$email->getHtmlBody(), $email->getTextBody()] as $body) {
            $this->assertStringContainsString('Sam Patient', $body);
            $this->assertStringContainsString('Dr. Rowan Hale', $body);
            $this->assertStringContainsString($slot->starts_at->format('l, j F Y'), $body);
            $this->assertStringContainsString($slot->starts_at->format('g:i A'), $body);
            $this->assertStringContainsString('Video call', $body);
            $this->assertStringContainsString($appointment->reference(), $body);
            $this->assertStringContainsString(route('doctor.appointments.show', $appointment), $body);
            $this->assertStringNotContainsString('Private symptom description', $body);
        }
        $this->assertLedger($appointment, AppointmentNotification::TYPE_BOOKED, AppointmentNotification::STATUS_SENT, $doctor);
    }

    public function test_failed_booking_sends_nothing(): void
    {
        $doctor = $this->doctor();
        $slot = $this->slot($doctor, status: 'blocked');

        $this->actingAs($this->patient())
            ->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])
            ->assertSessionHasErrors('slot');

        $this->assertCount(0, $this->sentEmails());
        $this->assertDatabaseCount('appointment_notifications', 0);
    }

    public function test_rolled_back_booking_transaction_sends_nothing_and_leaves_no_ledger_row(): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient();
        $slot = $this->slot($doctor);

        try {
            DB::transaction(function () use ($patient, $slot): void {
                app(AppointmentBookingService::class)->book($patient, $slot->id, 'physical');

                throw new RuntimeException('Simulated failure after booking inside the same transaction.');
            });
        } catch (RuntimeException) {
        }

        $this->assertCount(0, $this->sentEmails());
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_notifications', 0);
        $this->assertSame('available', $slot->fresh()->status);
    }

    public function test_email_is_only_dispatched_after_the_outer_transaction_commits(): void
    {
        $doctor = $this->doctor();
        $slot = $this->slot($doctor);

        DB::beginTransaction();
        app(AppointmentBookingService::class)->book($this->patient(), $slot->id, 'physical');
        $this->assertCount(0, $this->sentEmails(), 'Nothing may be sent while the transaction is still open.');
        DB::commit();

        $this->assertCount(1, $this->sentEmails());
    }

    public function test_doctor_manual_booking_sends_no_email(): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient();
        $slot = $this->slot($doctor);

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.store'), [
                'patient_email' => $patient->email,
                'slot_id' => $slot->id,
                'consultation_type' => 'physical',
            ])
            ->assertRedirect(route('doctor.schedule'));

        $this->assertDatabaseCount('appointments', 1);
        $this->assertCount(0, $this->sentEmails());
        $this->assertDatabaseCount('appointment_notifications', 0);
    }

    public function test_confirmation_emails_the_patient_once_even_when_repeated(): void
    {
        $doctor = $this->doctor('Dr. Ada Lin');
        $patient = $this->patient();
        $appointment = $this->appointment($doctor, $patient);

        $this->actingAs($doctor)->post(route('doctor.appointments.confirm', $appointment))->assertRedirect();
        $this->actingAs($doctor)->post(route('doctor.appointments.confirm', $appointment))->assertSessionHasErrors('status');

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame($patient->email, $emails->first()->getTo()[0]->getAddress());
        $this->assertStringStartsWith('Your appointment is confirmed', $emails->first()->getSubject());
        $this->assertStringContainsString('Dr. Ada Lin', $emails->first()->getHtmlBody());
        $this->assertStringContainsString(route('patient.appointments.show', $appointment), $emails->first()->getHtmlBody());
        $this->assertSame(1, $appointment->emailNotifications()->count());
    }

    public function test_rejection_emails_the_patient_and_invalid_transitions_send_nothing(): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient();
        $appointment = $this->appointment($doctor, $patient);
        $alreadyConfirmed = $this->appointment($doctor, $this->patient(), status: 'confirmed');

        $this->actingAs($doctor)->post(route('doctor.appointments.reject', $appointment))->assertRedirect();
        $this->actingAs($doctor)->post(route('doctor.appointments.reject', $appointment))->assertSessionHasErrors('status');
        $this->actingAs($doctor)->post(route('doctor.appointments.reject', $alreadyConfirmed))->assertSessionHasErrors('status');

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame($patient->email, $emails->first()->getTo()[0]->getAddress());
        $this->assertStringContainsString('unable to accept', $emails->first()->getTextBody());
        $this->assertStringContainsString($appointment->reference(), $emails->first()->getSubject());
        $this->assertSame(0, $alreadyConfirmed->emailNotifications()->count());
    }

    public function test_patient_cancellation_emails_the_doctor_once_and_rejected_cancellations_send_nothing(): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient('Casey Cancel');
        $appointment = $this->appointment($doctor, $patient, daysAhead: 5);
        $tooLate = $this->appointment($doctor, $patient, daysAhead: 0, hoursAhead: 3);

        $this->actingAs($patient)->post(route('appointments.cancel', $appointment))->assertRedirect();
        $this->actingAs($patient)->post(route('appointments.cancel', $appointment))->assertSessionHasErrors('appointment');
        $this->actingAs($patient)->post(route('appointments.cancel', $tooLate))->assertSessionHasErrors('appointment');

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame($doctor->email, $emails->first()->getTo()[0]->getAddress());
        $this->assertStringContainsString('Casey Cancel', $emails->first()->getHtmlBody());
        $this->assertStringContainsString($appointment->reference(), $emails->first()->getSubject());
        $this->assertSame(0, $tooLate->emailNotifications()->count());
    }

    public function test_reschedule_emails_the_patient_with_previous_and_new_times(): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient();
        $appointment = $this->appointment($doctor, $patient, status: 'confirmed');
        $previousSlot = $appointment->slot;
        $newSlot = $this->slot($doctor, daysAhead: 6);

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.reschedule.store', $appointment), ['slot_id' => $newSlot->id])
            ->assertRedirect();

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $email = $emails->first();
        $this->assertSame($patient->email, $email->getTo()[0]->getAddress());
        foreach ([$email->getHtmlBody(), $email->getTextBody()] as $body) {
            $this->assertStringContainsString('Previous time', $body);
            $this->assertStringContainsString($previousSlot->starts_at->format('l, j F Y'), $body);
            $this->assertStringContainsString($previousSlot->starts_at->format('g:i A'), $body);
            $this->assertStringContainsString('New time', $body);
            $this->assertStringContainsString($newSlot->starts_at->format('l, j F Y'), $body);
            $this->assertStringContainsString($newSlot->starts_at->format('g:i A'), $body);
        }
    }

    public function test_each_successful_reschedule_is_a_distinct_notification_and_failures_send_nothing(): void
    {
        $doctor = $this->doctor();
        $appointment = $this->appointment($doctor, $this->patient(), status: 'confirmed');
        $taken = $this->appointment($doctor, $this->patient())->slot;

        $this->actingAs($doctor)
            ->post(route('doctor.appointments.reschedule.store', $appointment), ['slot_id' => $taken->id])
            ->assertSessionHasErrors('slot_id');
        $this->assertCount(0, $this->sentEmails());

        foreach ([$this->slot($doctor, daysAhead: 7), $this->slot($doctor, daysAhead: 8)] as $slot) {
            $this->actingAs($doctor)
                ->post(route('doctor.appointments.reschedule.store', $appointment), ['slot_id' => $slot->id])
                ->assertRedirect();
        }

        $this->assertCount(2, $this->sentEmails());
        $this->assertSame(2, $appointment->emailNotifications()->where('type', AppointmentNotification::TYPE_RESCHEDULED)->count());
    }

    public function test_notifications_are_queued_rather_than_sent_during_the_request(): void
    {
        Queue::fake();
        Notification::fake();
        $doctor = $this->doctor();

        $this->actingAs($this->patient())
            ->post(route('appointment-slots.book', $this->slot($doctor)), ['consultation_type' => 'physical'])
            ->assertRedirect();

        Queue::assertPushed(SendAppointmentNotification::class, 1);
        Notification::assertNothingSent();
        $this->assertSame(AppointmentNotification::STATUS_PENDING, AppointmentNotification::firstOrFail()->status);

        $job = new SendAppointmentNotification(AppointmentNotification::firstOrFail()->id);
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(5, $job->maxExceptions, 'Five delivery failures are allowed before the job fails.');
        $this->assertGreaterThan($job->maxExceptions, $job->tries, 'Self-releases must not consume the delivery budget.');
        $this->assertNotEmpty($job->backoff);
    }

    public function test_failed_delivery_is_retried_and_a_delivered_email_is_never_sent_again(): void
    {
        Queue::fake();
        $doctor = $this->doctor();
        $patient = $this->patient();
        app(AppointmentBookingService::class)->book($patient, $this->slot($doctor)->id, 'physical');
        $ledger = AppointmentNotification::firstOrFail();
        $job = new SendAppointmentNotification($ledger->id);

        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP unavailable');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);

        try {
            $job->handle();
            $this->fail('A delivery failure must be rethrown so the queue retries the job.');
        } catch (TransportException) {
        }
        $ledger->refresh();
        $this->assertSame(AppointmentNotification::STATUS_FAILED, $ledger->status);
        $this->assertSame(1, $ledger->attempts);
        $this->assertSame('Email delivery failed.', $ledger->last_error);
        $this->assertNull($ledger->sent_at);
        $this->assertCount(0, $this->sentEmails());

        config(['mail.default' => 'array']);
        $job->handle();
        $job->handle();
        (new SendAppointmentNotification($ledger->id))->handle();

        $ledger->refresh();
        $this->assertSame(AppointmentNotification::STATUS_SENT, $ledger->status);
        $this->assertSame(2, $ledger->attempts);
        $this->assertNotNull($ledger->sent_at);
        $this->assertCount(1, $this->sentEmails(), 'Retries and duplicate jobs must not resend a delivered email.');
    }

    public function test_an_active_claim_blocks_other_workers_but_an_abandoned_claim_can_be_recovered(): void
    {
        Queue::fake();
        app(AppointmentBookingService::class)->book($this->patient(), $this->slot($this->doctor())->id, 'physical');
        $ledger = AppointmentNotification::firstOrFail();
        $ledger->forceFill(['status' => AppointmentNotification::STATUS_SENDING, 'attempts' => 1])->save();

        (new SendAppointmentNotification($ledger->id))->handle();
        $this->assertCount(0, $this->sentEmails());
        $this->assertSame(AppointmentNotification::STATUS_SENDING, $ledger->fresh()->status);

        $this->travel(SendAppointmentNotification::STALE_CLAIM_MINUTES + 1)->minutes();
        (new SendAppointmentNotification($ledger->id))->handle();
        $this->assertCount(1, $this->sentEmails());
        $this->assertSame(AppointmentNotification::STATUS_SENT, $ledger->fresh()->status);
    }

    public function test_deactivated_or_invalid_recipients_are_skipped(): void
    {
        Queue::fake();
        $deactivatedDoctor = $this->doctor();
        $invalidEmailPatient = $this->patient();
        $first = $this->appointment($deactivatedDoctor, $this->patient(), daysAhead: 5);
        $second = $this->appointment($this->doctor(), $invalidEmailPatient);

        app(AppointmentBookingService::class)->cancel($first->patient, $first->id);
        $deactivatedDoctor->forceFill(['deactivated_at' => now()])->save();
        app(DoctorAppointmentService::class)->transition($second->slot->doctorProfile->user, $second, 'confirmed');
        $invalidEmailPatient->forceFill(['email' => 'not-an-email'])->save();

        AppointmentNotification::all()->each(fn (AppointmentNotification $ledger) => (new SendAppointmentNotification($ledger->id))->handle());

        $this->assertCount(0, $this->sentEmails());
        $this->assertSame('Recipient account is deactivated.', $first->emailNotifications()->firstOrFail()->last_error);
        $this->assertSame('Recipient has no valid email address.', $second->emailNotifications()->firstOrFail()->last_error);
        $this->assertSame(2, AppointmentNotification::where('status', AppointmentNotification::STATUS_SKIPPED)->count());
    }

    public function test_outdated_emails_are_skipped_when_the_appointment_changed_before_delivery(): void
    {
        Queue::fake();
        $doctor = $this->doctor();
        $patient = $this->patient();
        $appointment = $this->appointment($doctor, $patient, daysAhead: 5);

        app(DoctorAppointmentService::class)->transition($doctor, $appointment, 'confirmed');
        app(AppointmentBookingService::class)->cancel($patient, $appointment->id);

        AppointmentNotification::query()->orderBy('id')->get()
            ->each(fn (AppointmentNotification $ledger) => (new SendAppointmentNotification($ledger->id))->handle());

        $emails = $this->sentEmails();
        $this->assertCount(1, $emails);
        $this->assertSame($doctor->email, $emails->first()->getTo()[0]->getAddress(), 'Only the cancellation reaches the doctor.');
        $this->assertSame(
            AppointmentNotification::STATUS_SKIPPED,
            $appointment->emailNotifications()->where('type', AppointmentNotification::TYPE_CONFIRMED)->firstOrFail()->status
        );
    }

    public function test_each_event_uses_its_notification_class_and_recipient(): void
    {
        Notification::fake();
        $doctor = $this->doctor();
        $patient = $this->patient();
        $booking = app(AppointmentBookingService::class);
        $service = app(DoctorAppointmentService::class);

        $booked = $booking->book($patient, $this->slot($doctor)->id, 'physical');
        $service->transition($doctor, $booked, 'confirmed');
        $service->reschedule($doctor, $booked, $this->slot($doctor, daysAhead: 9)->id);
        $booking->cancel($patient, $booked->id);
        $rejected = $booking->book($patient, $this->slot($doctor)->id, 'voice');
        $service->transition($doctor, $rejected, 'rejected');

        Notification::assertSentTo($doctor, AppointmentBookedNotification::class, 2);
        Notification::assertSentTo($patient, AppointmentConfirmedNotification::class, 1);
        Notification::assertSentTo($patient, AppointmentRescheduledNotification::class, 1);
        Notification::assertSentTo($doctor, AppointmentCancelledNotification::class, 1);
        Notification::assertSentTo($patient, AppointmentRejectedNotification::class, 1);
        Notification::assertNotSentTo($patient, AppointmentBookedNotification::class);
        Notification::assertNotSentTo($doctor, AppointmentConfirmedNotification::class);
        Notification::assertCount(6);
    }

    public function test_google_calendar_jobs_are_still_dispatched_alongside_emails(): void
    {
        Queue::fake();
        $doctor = $this->doctor();
        $appointment = $this->appointment($doctor, $this->patient());

        $this->actingAs($doctor)->post(route('doctor.appointments.confirm', $appointment))->assertRedirect();

        Queue::assertPushed(SyncAppointmentToGoogleCalendar::class, fn ($job) => $job->appointmentId === $appointment->id && $job->operation === 'sync');
        Queue::assertPushed(SendAppointmentNotification::class, 1);
    }

    /**
     * @return Collection<int, Email>
     */
    private function sentEmails(): Collection
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()
            ->map(fn ($sent) => $sent->getOriginalMessage());
    }

    private function assertLedger(Appointment $appointment, string $type, string $status, User $recipient): void
    {
        $this->assertDatabaseHas('appointment_notifications', [
            'appointment_id' => $appointment->id,
            'type' => $type,
            'transition_key' => $type,
            'status' => $status,
            'recipient_id' => $recipient->id,
            'attempts' => 1,
        ]);
    }

    private function appointment(User $doctor, User $patient, string $status = 'booked', int $daysAhead = 2, int $hoursAhead = 0): Appointment
    {
        $slot = $this->slot($doctor, $daysAhead, $hoursAhead, 'booked');

        return Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => $status,
            'consultation_type' => 'physical',
        ]);
    }

    private function slot(User $doctor, int $daysAhead = 3, int $hoursAhead = 0, string $status = 'available'): AppointmentSlot
    {
        $startsAt = now()->startOfHour()->addDays($daysAhead)->addHours($hoursAhead)->addMinutes(10 * $this->slotOffset++);

        return AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(10),
            'status' => $status,
        ]);
    }

    private function patient(?string $name = null): User
    {
        return User::factory()->create(['role' => 'patient', ...($name ? ['name' => $name] : [])]);
    }

    private function doctor(?string $name = null): User
    {
        $doctor = User::factory()->create(['role' => 'doctor', ...($name ? ['name' => $name] : [])]);
        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
        ]);

        return $doctor->load('doctorProfile');
    }
}
