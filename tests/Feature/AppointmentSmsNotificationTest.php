<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentNotification;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Services\AppointmentBookingService;
use App\Services\DoctorAppointmentService;
use App\Services\Sms\NullSmsGateway;
use App\Services\Sms\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\InteractsWithAppointmentSms;
use Tests\TestCase;

class AppointmentSmsNotificationTest extends TestCase
{
    use InteractsWithAppointmentSms;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeSms();
    }

    public function test_patient_booking_texts_the_patient_and_the_doctor_without_medical_details(): void
    {
        $doctor = $this->smsDoctor();
        $patient = $this->smsPatient('+880 1712-345678');
        $slot = $this->smsSlot($doctor, now()->addDays(3));

        $this->actingAs($patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'video'])->assertRedirect();

        $appointment = Appointment::firstOrFail();
        $toPatient = $this->sms->sentTo('8801712345678');
        $toDoctor = $this->sms->sentTo('8801812345678');
        $this->assertCount(1, $toPatient);
        $this->assertCount(1, $toDoctor);
        $this->assertStringContainsString($appointment->reference(), $toPatient[0]['message']);
        $this->assertStringContainsString('Dr. Rowan Hale', $toPatient[0]['message']);
        $this->assertStringContainsString('is booked', $toPatient[0]['message']);
        $this->assertStringContainsString('New appointment request', $toDoctor[0]['message']);
        $this->assertStringContainsString($slot->starts_at->format('j M Y, g:i A'), $toDoctor[0]['message']);

        foreach ($this->sms->sent as $sms) {
            $this->assertStringNotContainsString($patient->name, $sms['message'], 'SMS never carries the patient name.');
            $this->assertLessThanOrEqual(160, strlen($sms['message']));
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $sms['message'], 'Plain text fits a single GSM segment.');
        }
        $this->assertSame(
            $this->smsLedger()->map(fn ($row) => 'mydoc-notification-'.$row->id)->sort()->values()->all(),
            collect($this->sms->sent)->pluck('reference')->sort()->values()->all(),
            'Each SMS carries its ledger row as a stable idempotency reference.'
        );

        $this->assertSame(['booked:patient', 'booked:doctor'], $this->smsLedger()->pluck('transition_key')->all());
        $this->assertTrue($this->smsLedger()->every(fn ($row) => $row->status === 'sent' && $row->sent_at !== null && $row->provider_reference !== null));
        $this->assertDatabaseHas('appointment_notifications', ['channel' => 'email', 'type' => 'booked', 'transition_key' => 'booked', 'status' => 'sent', 'recipient_id' => $doctor->id]);
    }

    public function test_doctor_confirm_reject_and_reschedule_text_the_patient(): void
    {
        $doctor = $this->smsDoctor();
        $patient = $this->smsPatient();
        $confirmed = $this->smsAppointment($doctor, $patient, now()->addDays(3));
        $rejected = $this->smsAppointment($doctor, $patient, now()->addDays(4));
        $moved = $this->smsAppointment($doctor, $patient, now()->addDays(5), 'confirmed');
        $newSlot = $this->smsSlot($doctor, now()->addDays(6));

        $this->actingAs($doctor)->post(route('doctor.appointments.confirm', $confirmed))->assertRedirect();
        $this->actingAs($doctor)->post(route('doctor.appointments.reject', $rejected))->assertRedirect();
        $this->actingAs($doctor)->post(route('doctor.appointments.reschedule.store', $moved), ['slot_id' => $newSlot->id, 'reason' => 'Clinic closed for surgery'])->assertRedirect();

        $messages = collect($this->sms->sentTo('8801712345678'))->pluck('message');
        $this->assertCount(3, $messages);
        $this->assertStringContainsString($confirmed->reference().' with Dr. Rowan Hale', $messages[0]);
        $this->assertStringContainsString('is confirmed', $messages[0]);
        $this->assertStringContainsString('was declined', $messages[1]);
        $this->assertStringContainsString('has moved to '.$newSlot->starts_at->format('j M Y, g:i A'), $messages[2]);
        $this->assertStringNotContainsString('surgery', $messages[2], 'The reschedule reason is not sent by SMS.');
        $this->assertSame([], $this->sms->sentTo('8801812345678'), 'The doctor is not texted about their own actions.');
        $this->assertSame(['confirmed', 'rejected', 'rescheduled'], $this->smsLedger()->pluck('type')->all());
        $this->assertStringStartsWith('reschedule:', $this->smsLedger('rescheduled')->first()->transition_key);
    }

    public function test_patient_cancellation_texts_the_doctor(): void
    {
        $doctor = $this->smsDoctor();
        $patient = $this->smsPatient();
        $appointment = $this->smsAppointment($doctor, $patient, now()->addDays(3), 'confirmed');

        $this->actingAs($patient)->post(route('appointments.cancel', $appointment))->assertRedirect();

        $this->assertSame([], $this->sms->sentTo('8801712345678'));
        $toDoctor = $this->sms->sentTo('8801812345678');
        $this->assertCount(1, $toDoctor);
        $this->assertStringContainsString($appointment->reference().' on ', $toDoctor[0]['message']);
        $this->assertStringContainsString('was cancelled by the patient', $toDoctor[0]['message']);
    }

    public function test_doctor_created_booking_texts_the_patient_and_still_sends_no_email(): void
    {
        $doctor = $this->smsDoctor();
        $patient = $this->smsPatient();
        $slot = $this->smsSlot($doctor, now()->addDays(2));

        app(AppointmentBookingService::class)->bookForDoctor($doctor, $patient, $slot->id, 'physical', 'Bring previous reports');

        $this->assertCount(1, $this->sms->sentTo('8801712345678'));
        $this->assertSame([], $this->sms->sentTo('8801812345678'));
        $this->assertStringNotContainsString('previous reports', $this->sms->sent[0]['message']);
        $this->assertSame(0, AppointmentNotification::query()->where('channel', 'email')->count(), 'P1.2 behaviour: no email for doctor-created bookings.');
    }

    public function test_sms_is_sent_only_to_opted_in_users_and_never_changes_email(): void
    {
        $doctor = $this->smsDoctor(enabled: false);
        $patient = $this->smsPatient(enabled: false);
        $slot = $this->smsSlot($doctor, now()->addDays(3));

        $this->actingAs($patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])->assertRedirect();

        $this->assertSame([], $this->sms->sent);
        $this->assertCount(0, $this->smsLedger(), 'Opted-out users get no SMS ledger rows.');
        $this->assertDatabaseHas('appointment_notifications', ['channel' => 'email', 'type' => 'booked', 'status' => 'sent']);
    }

    public function test_preference_phone_and_account_are_rechecked_at_delivery(): void
    {
        $doctor = $this->smsDoctor();
        $optedOutLater = $this->smsPatient();
        $invalidPhone = $this->smsPatient('01712345679');
        $deactivated = $this->smsPatient('01712345670');
        $cases = [
            [$optedOutLater, fn () => $optedOutLater->forceFill(['sms_notifications_enabled' => false])->save(), 'Recipient has SMS notifications turned off.'],
            [$invalidPhone, fn () => $invalidPhone->forceFill(['phone' => '0123-not-a-mobile'])->save(), 'Recipient has no valid Bangladeshi mobile number.'],
            [$deactivated, fn () => $deactivated->forceFill(['deactivated_at' => now()])->save(), 'Recipient account is deactivated.'],
        ];

        $this->app['config']->set('queue.default', 'database');
        foreach ($cases as [$patient, $change, $reason]) {
            $appointment = $this->smsAppointment($doctor, $patient, now()->addDays(3));
            DB::transaction(fn () => app(DoctorAppointmentService::class)->transition($doctor, $appointment, 'confirmed'));
            $change();
            $row = $this->smsLedger('confirmed')->firstWhere('appointment_id', $appointment->id);
            (new SendAppointmentNotification($row->id))->handle();

            $this->assertSame('skipped', $row->fresh()->status);
            $this->assertSame($reason, $row->fresh()->last_error);
        }

        $this->assertSame([], $this->sms->sent);
    }

    public function test_the_current_phone_number_is_used_at_delivery(): void
    {
        $doctor = $this->smsDoctor();
        $patient = $this->smsPatient('01712345678');
        $appointment = $this->smsAppointment($doctor, $patient, now()->addDays(3));

        $this->app['config']->set('queue.default', 'database');
        DB::transaction(fn () => app(DoctorAppointmentService::class)->transition($doctor, $appointment, 'confirmed'));
        $patient->forceFill(['phone' => '+88 019-1234-5678'])->save();
        (new SendAppointmentNotification($this->smsLedger('confirmed')->first()->id))->handle();

        $this->assertSame([], $this->sms->sentTo('8801712345678'));
        $this->assertCount(1, $this->sms->sentTo('8801912345678'));
    }

    public function test_rolled_back_changes_send_no_sms(): void
    {
        $doctor = $this->smsDoctor();
        $patient = $this->smsPatient();
        $appointment = $this->smsAppointment($doctor, $patient, now()->addDays(3));

        try {
            DB::transaction(function () use ($doctor, $appointment): void {
                app(DoctorAppointmentService::class)->transition($doctor, $appointment, 'confirmed');

                throw new RuntimeException('Simulated failure after the change.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->sms->sent);
        $this->assertCount(0, AppointmentNotification::all());
        $this->assertSame('booked', $appointment->fresh()->status);
    }

    public function test_no_provider_configured_skips_sms_gracefully_and_email_still_works(): void
    {
        $this->fakeSms(configured: false);
        $doctor = $this->smsDoctor();
        $patient = $this->smsPatient();
        $slot = $this->smsSlot($doctor, now()->addDays(3));

        $this->actingAs($patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])->assertRedirect();

        $this->assertSame([], $this->sms->sent);
        $this->assertTrue($this->smsLedger()->every(fn ($row) => $row->status === 'skipped' && $row->last_error === 'No SMS provider is configured.'));
        $this->assertDatabaseHas('appointment_notifications', ['channel' => 'email', 'status' => 'sent']);
    }

    public function test_the_default_binding_in_tests_never_uses_a_real_provider(): void
    {
        $this->app->forgetInstance(SmsGateway::class);
        $this->app['config']->set('sms.default', 'bulksmsbd');
        $this->app['config']->set('sms.drivers.bulksmsbd.api_key', 'should-not-be-used');
        $this->app['config']->set('sms.sender_id', '8809600000000');

        $this->assertInstanceOf(NullSmsGateway::class, $this->app->make(SmsGateway::class));
    }
}
