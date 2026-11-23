<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Notifications\AppointmentConfirmedNotification;
use App\Services\AppointmentBookingService;
use App\Services\ConsultationFee;
use App\Services\DoctorAppointmentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 8: every new appointment stores the fee that applied when it was created; later fee changes never rewrite it.
 */
class ConsultationFeeSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private DoctorProfile $profile;

    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        $this->travelTo(CarbonImmutable::create(2026, 11, 2, 8, 0, 0, config('app.timezone')));
        $this->doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr. Rowan Hale']);
        $this->profile = DoctorProfile::create(['user_id' => $this->doctor->id, 'professional_title' => 'Physician', 'license_number' => 'LIC-FEE', 'consultation_fee' => 1500, 'is_accepting_patients' => true]);
        $this->patient = User::factory()->create(['role' => 'patient']);
    }

    public function test_a_booking_keeps_its_fee_when_the_doctor_changes_fees_later(): void
    {
        $slot = $this->slot('2026-11-09 09:00');
        $this->actingAs($this->patient)->get(route('appointment-slots.confirm', $slot))->assertOk()->assertSee('BDT 1,500.00')->assertSee('does not change if the doctor');

        $this->actingAs($this->patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])->assertSessionHasNoErrors();
        $appointment = Appointment::sole();
        $this->assertSame(['1500.00', ConsultationFee::TYPE_STANDARD], [$appointment->consultation_fee, $appointment->fee_type]);

        $this->profile->update(['consultation_fee' => 2000]);
        app(DoctorAppointmentService::class)->transition($this->doctor, $appointment, 'confirmed');
        app(DoctorAppointmentService::class)->reschedule($this->doctor, $appointment->fresh(), $this->slot('2026-11-10 09:00')->id);

        $this->assertSame('1500.00', $appointment->fresh()->consultation_fee, 'Rescheduling and fee changes never recalculate it.');
        $this->actingAs($this->patient)->get(route('patient.appointments.show', $appointment))->assertSee('BDT 1,500.00')->assertDontSee('BDT 2,000.00');
        $this->actingAs($this->doctor)->get(route('doctor.appointments.show', $appointment))->assertSee('BDT 1,500.00');
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.appointments.show', $appointment))->assertSee('BDT 1,500.00');
        $this->assertStringContainsString('BDT 1,500.00', (string) (new AppointmentConfirmedNotification($appointment->fresh()))->toMail($this->patient)->render());

        // The profile shows the new fee for new bookings.
        $this->actingAs($this->patient)->get(route('doctors.show', $this->profile))->assertSee('BDT 2,000.00');
    }

    public function test_staff_bookings_store_the_fee_too(): void
    {
        $appointment = app(AppointmentBookingService::class)->bookForDoctor($this->doctor, $this->patient, $this->slot('2026-11-09 09:00')->id, 'physical');

        $this->assertSame(['1500.00', ConsultationFee::TYPE_STANDARD], [$appointment->fresh()->consultation_fee, $appointment->fresh()->fee_type]);
    }

    public function test_follow_ups_use_the_follow_up_fee_when_set_and_the_regular_fee_otherwise(): void
    {
        $original = $this->appointment('2026-10-20 09:00', 'completed');
        $booking = app(AppointmentBookingService::class);

        $withoutFollowUpFee = $booking->book($this->patient, $this->slot('2026-11-09 09:00')->id, 'physical', $original->id);
        $this->assertSame(['1500.00', ConsultationFee::TYPE_FOLLOW_UP], [$withoutFollowUpFee->fresh()->consultation_fee, $withoutFollowUpFee->fresh()->fee_type]);
        $booking->cancel($this->patient, $withoutFollowUpFee->id);

        $this->profile->update(['follow_up_fee' => 800]);
        $slot = $this->slot('2026-11-10 09:00');
        $this->actingAs($this->patient)->get(route('appointment-slots.confirm', [$slot, 'follow_up' => $original->id]))->assertOk()
            ->assertSee('BDT 800.00')->assertSee('(follow-up)');
        $this->actingAs($this->patient)->get(route('appointment-slots.confirm', $slot))->assertSee('BDT 1,500.00')->assertDontSee('BDT 800.00');

        $this->actingAs($this->patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical', 'follow_up_of_id' => $original->id])->assertSessionHasNoErrors();
        $followUp = Appointment::where('appointment_slot_id', $slot->id)->sole();
        $this->assertSame(['800.00', ConsultationFee::TYPE_FOLLOW_UP], [$followUp->consultation_fee, $followUp->fee_type]);
        $this->actingAs($this->patient)->get(route('patient.appointments.show', $followUp))->assertSee('Follow-up fee:')->assertSee('BDT 800.00');

        // A regular booking is never charged the follow-up fee, and a submitted fee is ignored.
        $regular = $this->slot('2026-11-11 09:00');
        $this->actingAs($this->patient)->post(route('appointment-slots.book', $regular), ['consultation_type' => 'physical', 'consultation_fee' => 1, 'fee_type' => 'follow_up'])->assertSessionHasNoErrors();
        $this->assertSame(['1500.00', ConsultationFee::TYPE_STANDARD], Appointment::where('appointment_slot_id', $regular->id)->get(['consultation_fee', 'fee_type'])->map(fn ($a) => [$a->consultation_fee, $a->fee_type])->sole());

        $this->actingAs($this->patient)->get(route('doctors.show', $this->profile))->assertSee('Follow-up fee:')->assertSee('BDT 800.00');
    }

    public function test_appointments_without_a_stored_fee_show_not_recorded_instead_of_the_current_fee(): void
    {
        $legacy = $this->appointment('2026-11-09 09:00', 'confirmed');

        $this->actingAs($this->patient)->get(route('patient.appointments.show', $legacy))->assertSee('Not recorded')->assertDontSee('BDT 1,500.00');
        $this->actingAs($this->doctor)->get(route('doctor.appointments.show', $legacy))->assertSee('Not recorded');
    }

    public function test_fees_are_validated_in_the_doctor_and_admin_forms(): void
    {
        $base = ['professional_title' => 'Physician', 'license_number' => 'LIC-FEE', 'consultation_fee' => '1500'];

        foreach ([
            ['consultation_fee' => '-1'],
            ['consultation_fee' => '10.555'],
            ['consultation_fee' => '100000000'],
            ['follow_up_fee' => '1600'],
            ['follow_up_fee' => '-5'],
            ['follow_up_fee' => '5.123'],
        ] as $invalid) {
            $this->actingAs($this->doctor)->put(route('doctor.profile.update'), [...$base, ...$invalid])->assertSessionHasErrors(array_key_first($invalid));
        }
        $this->actingAs($this->doctor)->put(route('doctor.profile.update'), [...$base, 'follow_up_fee' => '1600'])
            ->assertSessionHasErrors(['follow_up_fee' => 'The follow-up fee cannot be more than the consultation fee.']);

        $this->actingAs($this->doctor)->put(route('doctor.profile.update'), [...$base, 'follow_up_fee' => '750.50'])->assertSessionHasNoErrors();
        $this->assertSame('750.50', (string) $this->profile->fresh()->follow_up_fee);
        $this->actingAs($this->doctor)->put(route('doctor.profile.update'), [...$base, 'follow_up_fee' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->profile->fresh()->follow_up_fee, 'Clearing it falls back to the consultation fee.');

        $admin = User::factory()->create(['role' => 'admin']);
        $adminPayload = ['name' => 'Dr. Rowan Hale', 'email' => $this->doctor->email, ...$base];
        $this->actingAs($admin)->patch(route('admin.doctors.update', $this->profile), [...$adminPayload, 'follow_up_fee' => '2000'])->assertSessionHasErrors('follow_up_fee');
        $this->actingAs($admin)->patch(route('admin.doctors.update', $this->profile), [...$adminPayload, 'follow_up_fee' => '900'])->assertSessionHasNoErrors();
        $this->assertSame('900.00', (string) $this->profile->fresh()->follow_up_fee);
    }

    public function test_backfill_records_only_provable_fees_and_keeps_timestamps(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 1, 9, 0, 0, config('app.timezone')));
        $provable = $this->appointment('2026-10-05 09:00', 'completed', bookedAt: now()->addDay());
        DB::table('appointments')->where('id', $provable->id)->update(['updated_at' => '2026-10-02 10:00:00']);

        $editedDoctor = User::factory()->create(['role' => 'doctor']);
        $editedProfile = DoctorProfile::create(['user_id' => $editedDoctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-EDITED', 'consultation_fee' => 300]);
        $unprovable = $this->appointment('2026-10-06 09:00', 'completed', profile: $editedProfile, bookedAt: now());
        DB::table('doctor_profiles')->where('id', $editedProfile->id)->update(['updated_at' => now()->addDays(3)]);
        $followUp = $this->appointment('2026-10-07 09:00', 'booked', bookedAt: now()->addDays(2));
        $followUp->update(['follow_up_of_id' => $provable->id]);
        DB::table('doctor_profiles')->where('id', $this->profile->id)->update(['updated_at' => now()]);

        $this->backfill()->up();

        $this->assertSame(['1500.00', 'standard', '2026-10-02 10:00:00'], [$provable->fresh()->consultation_fee, $provable->fresh()->fee_type, (string) DB::table('appointments')->where('id', $provable->id)->value('updated_at')]);
        $this->assertNull($unprovable->fresh()->consultation_fee, 'The profile was edited after booking, so the fee at booking is unknown.');
        $this->assertNull($followUp->fresh()->consultation_fee);

        $this->backfill()->up();
        $this->assertSame('1500.00', $provable->fresh()->consultation_fee, 'Running it again changes nothing.');

        $this->backfill()->down();
        $this->assertSame([null, null, '2026-10-02 10:00:00'], [$provable->fresh()->consultation_fee, $provable->fresh()->fee_type, (string) DB::table('appointments')->where('id', $provable->id)->value('updated_at')]);
    }

    private function backfill(): Migration
    {
        return require database_path('migrations/2026_11_22_224830_backfill_verifiable_appointment_fee_snapshots.php');
    }

    private function slot(string $startsAt, string $status = 'available', ?DoctorProfile $profile = null): AppointmentSlot
    {
        $start = CarbonImmutable::parse($startsAt, config('app.timezone'));

        return AppointmentSlot::create(['doctor_profile_id' => ($profile ?? $this->profile)->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(10), 'status' => $status]);
    }

    private function appointment(string $startsAt, string $status, ?DoctorProfile $profile = null, ?\DateTimeInterface $bookedAt = null): Appointment
    {
        return Appointment::create([
            'appointment_slot_id' => $this->slot($startsAt, 'booked', $profile)->id,
            'patient_id' => $this->patient->id,
            'status' => $status,
            'consultation_type' => 'physical',
            'booked_at' => $bookedAt ?? now(),
        ]);
    }
}
