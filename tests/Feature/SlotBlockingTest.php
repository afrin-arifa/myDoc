<?php

namespace Tests\Feature;

use App\Jobs\OfferSlotToWaitlist;
use App\Jobs\SendAppointmentNotification;
use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\SlotBlockingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\AssertsUnavailableSlotRedirect;
use Tests\TestCase;

class SlotBlockingTest extends TestCase
{
    use AssertsUnavailableSlotRedirect, RefreshDatabase;

    private int $slotOffset = 0;

    public function test_doctor_can_block_and_unblock_their_own_free_future_slot(): void
    {
        Queue::fake();
        $doctor = $this->doctor();
        $slot = $this->slot($doctor);

        $this->actingAs($doctor)->post(route('doctor.slots.block', $slot))->assertRedirect()->assertSessionHas('status');
        $this->assertSame('blocked', $slot->fresh()->status);

        $this->actingAs($doctor)->delete(route('doctor.slots.unblock', $slot))->assertRedirect()->assertSessionHas('status');
        $this->assertSame('available', $slot->fresh()->status);
        Queue::assertNotPushed(SendAppointmentNotification::class);
        Queue::assertNotPushed(SyncAppointmentToGoogleCalendar::class);
        // The reopened time is offered to the waiting list, if anyone is waiting for it.
        Queue::assertPushed(OfferSlotToWaitlist::class, fn ($job) => $job->slotId === $slot->id);
    }

    public function test_blocked_slot_cannot_be_booked_by_patients_doctors_or_rescheduling(): void
    {
        Queue::fake();
        $doctor = $this->doctor();
        $patient = User::factory()->create(['role' => 'patient']);
        $slot = $this->slot($doctor);
        $confirmed = $this->appointment($doctor, $patient, 'confirmed');
        $this->actingAs($doctor)->post(route('doctor.slots.block', $slot));

        $this->assertSentToChooseAnotherTime($this->actingAs($patient)->get(route('appointment-slots.confirm', $slot)), $slot);
        $this->assertSentToChooseAnotherTime($this->actingAs($patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical']), $slot);
        $this->actingAs($doctor)->post(route('doctor.appointments.store'), ['patient_email' => $patient->email, 'slot_id' => $slot->id, 'consultation_type' => 'physical'])->assertSessionHasErrors('slot');
        $this->actingAs($doctor)->post(route('doctor.appointments.reschedule.store', $confirmed), ['slot_id' => $slot->id])->assertSessionHasErrors('slot_id');
        $this->get(route('doctors.show', $doctor->doctorProfile))->assertDontSee($slot->starts_at->format('g:i A'));

        $this->assertSame('blocked', $slot->fresh()->status);
        $this->assertSame(1, Appointment::count());
    }

    public function test_booked_confirmed_or_already_reserved_slots_cannot_be_blocked(): void
    {
        $doctor = $this->doctor();
        $booked = $this->appointment($doctor, User::factory()->create(['role' => 'patient']), 'booked');
        $confirmed = $this->appointment($doctor, User::factory()->create(['role' => 'patient']), 'confirmed');

        foreach ([$booked, $confirmed] as $appointment) {
            $this->actingAs($doctor)->post(route('doctor.slots.block', $appointment->slot))->assertSessionHasErrors('slot');
            $this->assertSame('booked', $appointment->slot->fresh()->status);
            $this->assertSame($appointment->status, $appointment->fresh()->status);
        }

        // Defence in depth: even if a slot row says "available", an active appointment on it prevents blocking.
        $booked->slot->forceFill(['status' => 'available'])->save();
        $this->actingAs($doctor)->post(route('doctor.slots.block', $booked->slot))->assertSessionHasErrors('slot');
        $this->assertSame('available', $booked->slot->fresh()->status);
    }

    public function test_only_blocked_slots_can_be_unblocked_and_past_slots_cannot_change(): void
    {
        $doctor = $this->doctor();
        $available = $this->slot($doctor);
        $booked = $this->appointment($doctor, User::factory()->create(['role' => 'patient']), 'booked');
        $pastAvailable = $this->slot($doctor, -2);
        $pastBlocked = $this->slot($doctor, -1, 'blocked');

        $this->actingAs($doctor)->delete(route('doctor.slots.unblock', $available))->assertSessionHasErrors('slot');
        $this->actingAs($doctor)->delete(route('doctor.slots.unblock', $booked->slot))->assertSessionHasErrors('slot');
        $this->actingAs($doctor)->post(route('doctor.slots.block', $pastAvailable))->assertSessionHasErrors('slot');
        $this->actingAs($doctor)->delete(route('doctor.slots.unblock', $pastBlocked))->assertSessionHasErrors('slot');

        $this->assertSame('available', $available->fresh()->status);
        $this->assertSame('booked', $booked->slot->fresh()->status);
        $this->assertSame('available', $pastAvailable->fresh()->status);
        $this->assertSame('blocked', $pastBlocked->fresh()->status);
    }

    public function test_doctors_cannot_block_or_unblock_another_doctors_slots(): void
    {
        $doctor = $this->doctor();
        $otherDoctor = $this->doctor();
        $theirFree = $this->slot($otherDoctor);
        $theirBlocked = $this->slot($otherDoctor, 3, 'blocked');

        $this->actingAs($doctor)->post(route('doctor.slots.block', $theirFree))->assertForbidden();
        $this->actingAs($doctor)->delete(route('doctor.slots.unblock', $theirBlocked))->assertForbidden();
        $this->assertSame('available', $theirFree->fresh()->status);
        $this->assertSame('blocked', $theirBlocked->fresh()->status);

        $this->expectException(ModelNotFoundException::class);
        app(SlotBlockingService::class)->block($doctor, $theirFree);
    }

    public function test_patients_admins_guests_and_deactivated_doctors_cannot_block_slots(): void
    {
        $doctor = $this->doctor();
        $slot = $this->slot($doctor);

        $this->post(route('doctor.slots.block', $slot))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'patient']))->post(route('doctor.slots.block', $slot))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('doctor.slots.block', $slot))->assertForbidden();
        $doctor->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($doctor->fresh())->post(route('doctor.slots.block', $slot))->assertForbidden();

        $this->assertSame('available', $slot->fresh()->status);
    }

    public function test_block_and_booking_are_serialized_on_the_slot_row(): void
    {
        $doctor = $this->doctor();
        $patient = User::factory()->create(['role' => 'patient']);
        $slot = $this->slot($doctor);
        $staleSlot = AppointmentSlot::findOrFail($slot->id);

        // Booking wins first: the block re-reads the locked row and refuses.
        app(AppointmentBookingService::class)->book($patient, $slot->id, 'physical');
        try {
            app(SlotBlockingService::class)->block($doctor, $staleSlot);
            $this->fail('A booked slot must not be blocked, even through a stale model.');
        } catch (ValidationException) {
        }
        $this->assertSame('booked', $slot->fresh()->status);

        // Block wins first: booking re-reads the locked row and refuses.
        $other = $this->slot($doctor);
        $staleOther = AppointmentSlot::findOrFail($other->id);
        app(SlotBlockingService::class)->block($doctor, $other);
        try {
            app(AppointmentBookingService::class)->book($patient, $staleOther->id, 'physical');
            $this->fail('A blocked slot must not be booked.');
        } catch (ValidationException) {
        }
        $this->assertSame('blocked', $other->fresh()->status);
        $this->assertSame(1, Appointment::count());
    }

    public function test_slot_generation_keeps_blocked_slots_and_calendar_shows_block_controls(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 2, 7, 0, 0, config('app.timezone')));
        $doctor = $this->doctor();
        DoctorAvailability::create(['doctor_profile_id' => $doctor->doctorProfile->id, 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '09:30', 'slot_duration_minutes' => 10, 'is_active' => true]);
        $range = ['start_date' => '2026-11-02', 'end_date' => '2026-11-02', 'weekdays' => [1]];
        $this->actingAs($doctor)->post(route('doctor.slots.generate'), $range);
        $slots = AppointmentSlot::query()->orderBy('starts_at')->get();
        $this->actingAs($doctor)->post(route('doctor.slots.block', $slots[1]));

        $this->actingAs($doctor)->post(route('doctor.slots.generate'), $range);
        $this->assertSame(['available', 'blocked', 'available'], AppointmentSlot::query()->orderBy('starts_at')->pluck('status')->all());

        $calendar = $this->actingAs($doctor)->get(route('doctor.schedule', ['view' => 'day', 'date' => '2026-11-02']))->assertOk();
        $calendar->assertSee('data-event-type="blocked"', false)
            ->assertSee(route('doctor.slots.unblock', $slots[1]), false)
            ->assertSee(route('doctor.slots.block', $slots[0]), false)
            ->assertSee('Blocked slot');
        $this->assertSame(1, $calendar->viewData('summary')['blocked']);

        $this->actingAs($doctor)->get(route('doctor.schedule', ['view' => 'day', 'date' => '2026-11-02', 'filters' => ['available']]))
            ->assertDontSee('data-event-type="blocked"', false)
            ->assertSee('data-event-type="available"', false);
    }

    public function test_cancelling_an_appointment_still_frees_its_slot_and_calendar_sync_is_unchanged(): void
    {
        Queue::fake();
        $doctor = $this->doctor();
        $patient = User::factory()->create(['role' => 'patient']);
        $appointment = $this->appointment($doctor, $patient, 'confirmed', 5);

        $this->actingAs($patient)->post(route('appointments.cancel', $appointment))->assertRedirect();

        $this->assertSame('available', $appointment->slot->fresh()->status);
        Queue::assertPushed(SyncAppointmentToGoogleCalendar::class, fn ($job) => $job->operation === 'delete');
        $this->actingAs($doctor)->post(route('doctor.slots.block', $appointment->slot))->assertSessionHas('status');
        $this->assertSame('blocked', $appointment->slot->fresh()->status);
    }

    private function appointment(User $doctor, User $patient, string $status, int $daysAhead = 3): Appointment
    {
        return Appointment::create([
            'appointment_slot_id' => $this->slot($doctor, $daysAhead, 'booked')->id,
            'patient_id' => $patient->id,
            'status' => $status,
            'consultation_type' => 'physical',
        ]);
    }

    private function slot(User $doctor, int $daysAhead = 3, string $status = 'available'): AppointmentSlot
    {
        $startsAt = now()->startOfHour()->addDays($daysAhead)->addMinutes(10 * $this->slotOffset++);

        return AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(10),
            'status' => $status,
        ]);
    }

    private function doctor(): User
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        return $doctor->load('doctorProfile');
    }
}
