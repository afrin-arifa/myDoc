<?php

namespace Tests\Concerns;

use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\Sms\FakeSmsGateway;
use App\Services\Sms\SmsGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared setup for SMS tests: a fake provider (no real SMS is ever sent) and appointment builders.
 */
trait InteractsWithAppointmentSms
{
    protected FakeSmsGateway $sms;

    private int $smsSlotOffset = 0;

    protected function fakeSms(bool $configured = true): FakeSmsGateway
    {
        $this->sms = new FakeSmsGateway($configured);
        $this->app->instance(SmsGateway::class, $this->sms);

        return $this->sms;
    }

    protected function smsPatient(string $phone = '01712345678', bool $enabled = true, array $attributes = []): User
    {
        return User::factory()->create(['role' => 'patient', 'phone' => $phone, 'sms_notifications_enabled' => $enabled, ...$attributes]);
    }

    protected function smsDoctor(string $phone = '01812345678', bool $enabled = true, string $name = 'Dr. Rowan Hale'): User
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name, 'phone' => $phone, 'sms_notifications_enabled' => $enabled]);
        DoctorProfile::create([
            'user_id' => $doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$doctor->id,
            'consultation_fee' => 50,
        ]);

        return $doctor->load('doctorProfile');
    }

    protected function smsSlot(User $doctor, \DateTimeInterface $startsAt, string $status = 'available'): AppointmentSlot
    {
        $startsAt = Carbon::instance($startsAt)->addSeconds($this->smsSlotOffset++);

        return AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(10),
            'status' => $status,
        ]);
    }

    protected function smsAppointment(User $doctor, User $patient, \DateTimeInterface $startsAt, string $status = 'booked'): Appointment
    {
        $slot = $this->smsSlot($doctor, $startsAt, 'booked');

        return Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => $patient->id,
            'status' => $status,
            'consultation_type' => 'physical',
            'patient_notes' => 'Private symptom description',
        ]);
    }

    /**
     * @return Collection<int, AppointmentNotification>
     */
    protected function smsLedger(?string $type = null): Collection
    {
        return AppointmentNotification::query()
            ->where('channel', AppointmentNotification::CHANNEL_SMS)
            ->when($type, fn ($query) => $query->where('type', $type))
            ->orderBy('id')
            ->get();
    }
}
