<?php

namespace Tests\Feature;

use App\Jobs\SyncAppointmentToGoogleCalendar;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use App\Notifications\AppointmentBookedNotification;
use App\Services\AppointmentBookingService;
use App\Services\DoctorAppointmentService;
use App\Services\PatientAppointmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 7: follow-up appointments link to the patient's completed appointment with the same doctor, are booked through
 * the normal booking rules by patients and staff, and show their relationship wherever appointments are shown.
 */
class FollowUpAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private DoctorProfile $profile;

    private User $patient;

    private Appointment $original;

    private int $minuteOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SyncAppointmentToGoogleCalendar::class]);
        $this->travelTo(CarbonImmutable::create(2026, 11, 2, 8, 0, 0, config('app.timezone')));
        [$this->doctor, $this->profile] = $this->doctorWithProfile('Dr. Rowan Hale');
        $this->patient = User::factory()->create(['role' => 'patient', 'name' => 'Sam Patient']);
        $this->original = $this->appointment($this->profile, $this->patient, '2026-10-20 09:00', 'completed');
    }

    public function test_a_patient_books_a_follow_up_from_their_completed_appointment(): void
    {
        $slot = $this->slot($this->profile, '2026-11-09 09:00');

        $this->actingAs($this->patient)->get(route('patient.appointments.show', $this->original))->assertOk()
            ->assertSee('Book a follow-up')
            ->assertSee(route('doctors.show', ['doctor' => $this->profile, 'follow_up' => $this->original->id]), false);

        $this->actingAs($this->patient)->get(route('doctors.show', ['doctor' => $this->profile, 'date' => '2026-11-09', 'follow_up' => $this->original->id]))->assertOk()
            ->assertSee('Booking a follow-up to your appointment '.$this->original->reference())
            ->assertSee(route('appointment-slots.confirm', [$slot, 'follow_up' => $this->original->id]), false);

        $this->actingAs($this->patient)->get(route('appointment-slots.confirm', [$slot, 'follow_up' => $this->original->id]))->assertOk()
            ->assertSee('This is a follow-up to your appointment '.$this->original->reference())
            ->assertSee('name="follow_up_of_id" value="'.$this->original->id.'"', false);

        $this->actingAs($this->patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical', 'follow_up_of_id' => $this->original->id])
            ->assertRedirect(route('patient.appointments.index'))->assertSessionHasNoErrors();

        $followUp = Appointment::whereKeyNot($this->original->id)->sole();
        $this->assertSame([$this->original->id, 'booked', $slot->id], [$followUp->follow_up_of_id, $followUp->status, $followUp->appointment_slot_id]);
        $this->assertSame($this->original->id, DoctorWorkspaceEvent::where('action', 'booked')->sole()->context['follow_up_of_id']);
        $mail = (string) (new AppointmentBookedNotification($followUp))->toMail($this->doctor)->render();
        $this->assertStringContainsString($this->original->reference(), $mail, 'The doctor\'s email names the original by reference.');

        // The original now has an active follow-up, so no second one is offered.
        $this->actingAs($this->patient)->get(route('patient.appointments.show', $this->original))->assertDontSee('Book a follow-up')
            ->assertSee('Follow-up:')->assertSee($followUp->reference());
        $this->actingAs($this->patient)->get(route('patient.appointments.show', $followUp))->assertSee('Follow-up to')->assertSee($this->original->reference());
        $this->actingAs($this->patient)->get(route('patient.appointments.index'))->assertSee('Follow-up to');
    }

    public function test_only_the_patients_own_completed_appointment_with_the_same_doctor_can_be_followed_up(): void
    {
        [, $otherProfile] = $this->doctorWithProfile('Dr. Other');
        $otherPatient = User::factory()->create(['role' => 'patient']);
        $ineligible = [
            'confirmed original' => $this->appointment($this->profile, $this->patient, '2026-10-21 09:00', 'confirmed'),
            'cancelled original' => $this->appointment($this->profile, $this->patient, '2026-10-22 09:00', 'cancelled'),
            'another patient\'s appointment' => $this->appointment($this->profile, $otherPatient, '2026-10-23 09:00', 'completed'),
            'another doctor\'s appointment' => $this->appointment($otherProfile, $this->patient, '2026-10-24 09:00', 'completed'),
        ];
        $slot = $this->slot($this->profile, '2026-11-09 09:00');

        foreach ([...$ineligible, 'unknown id' => 999999] as $case => $original) {
            $originalId = $original instanceof Appointment ? $original->id : $original;
            $this->actingAs($this->patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical', 'follow_up_of_id' => $originalId])
                ->assertSessionHasErrors(['follow_up_of_id' => PatientAppointmentRules::FOLLOW_UP_INELIGIBLE_MESSAGE]);
            $this->assertSame('available', $slot->fresh()->status, $case);

            // Pages never show follow-up mode for it.
            $this->actingAs($this->patient)->get(route('doctors.show', ['doctor' => $this->profile, 'follow_up' => $originalId]))->assertOk()->assertDontSee('data-follow-up-banner', false);
            $this->actingAs($this->patient)->get(route('appointment-slots.confirm', [$slot, 'follow_up' => $originalId]))->assertOk()->assertDontSee('follow_up_of_id', false);
        }

        $this->actingAs($this->patient)->get(route('patient.appointments.show', $ineligible['confirmed original']))->assertDontSee('Book a follow-up');
        $this->assertSame(0, Appointment::whereNotNull('follow_up_of_id')->count());
    }

    public function test_an_original_has_at_most_one_active_follow_up_at_a_time(): void
    {
        $booking = app(AppointmentBookingService::class);
        $first = $booking->book($this->patient, $this->slot($this->profile, '2026-11-09 09:00')->id, 'physical', $this->original->id);

        try {
            $booking->book($this->patient, $this->slot($this->profile, '2026-11-10 09:00')->id, 'physical', $this->original->id);
            $this->fail('A second active follow-up was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['You already have a pending or confirmed follow-up for appointment '.$this->original->reference().'.'], $exception->errors()['follow_up_of_id']);
        }

        $booking->cancel($this->patient, $first->id);
        $second = $booking->book($this->patient, $this->slot($this->profile, '2026-11-11 09:00')->id, 'physical', $this->original->id);

        $this->assertSame($this->original->id, $second->follow_up_of_id);
        $this->assertSame(['cancelled', 'booked'], Appointment::where('follow_up_of_id', $this->original->id)->orderBy('id')->pluck('status')->all());
        $this->assertSame($this->original->id, $first->fresh()->follow_up_of_id, 'Cancelling keeps the link as history.');
    }

    public function test_follow_ups_keep_every_normal_booking_rule(): void
    {
        $booking = app(AppointmentBookingService::class);
        foreach (['2026-11-09 09:00', '2026-11-09 10:00', '2026-11-09 11:00'] as $time) {
            $booking->book($this->patient, $this->slot($this->profile, $time)->id, 'physical');
        }

        $this->expectRefusal(fn () => $booking->book($this->patient, $this->slot($this->profile, '2026-11-10 09:00')->id, 'physical', $this->original->id), 'slot');

        Appointment::where('status', 'booked')->orderByDesc('id')->first()->update(['status' => 'cancelled']);
        $taken = $this->slot($this->profile, '2026-11-12 09:00', 'blocked');
        $this->expectRefusal(fn () => $booking->book($this->patient, $taken->id, 'physical', $this->original->id), 'slot');
        $overlapping = $this->slot($this->profile, '2026-11-09 09:05');
        $this->expectRefusal(fn () => $booking->book($this->patient, $overlapping->id, 'physical', $this->original->id), 'slot');
        $past = $this->slot($this->profile, '2026-11-01 09:00');
        $this->expectRefusal(fn () => $booking->book($this->patient, $past->id, 'physical', $this->original->id), 'slot');

        $this->assertSame(0, Appointment::whereNotNull('follow_up_of_id')->count());
    }

    public function test_staff_book_a_follow_up_from_the_completed_appointment_page(): void
    {
        $slot = $this->slot($this->profile, '2026-11-09 09:00');

        $this->actingAs($this->doctor)->get(route('doctor.appointments.show', $this->original))->assertOk()
            ->assertSee('Book follow-up')
            ->assertSee(route('doctor.appointments.create', ['follow_up' => $this->original->id]), false);

        $this->actingAs($this->doctor)->get(route('doctor.appointments.create', ['follow_up' => $this->original->id, 'date' => '2026-11-09']))->assertOk()
            ->assertSee('Follow-up for')->assertSee('Sam Patient')->assertSee($this->original->reference())
            ->assertSee('name="patient_id" type="hidden" value="'.$this->patient->id.'"', false)
            ->assertSee('name="follow_up_of_id" type="hidden" value="'.$this->original->id.'"', false)
            ->assertDontSee('id="patient_email"', false);

        $this->actingAs($this->doctor)->post(route('doctor.appointments.store'), [
            'patient_id' => $this->patient->id, 'slot_id' => $slot->id, 'consultation_type' => 'physical', 'follow_up_of_id' => $this->original->id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('doctor.schedule'));

        $followUp = Appointment::where('appointment_slot_id', $slot->id)->sole();
        $this->assertSame($this->original->id, $followUp->follow_up_of_id);
        $this->assertSame(['staff', $this->original->id], [DoctorWorkspaceEvent::where('action', 'booked')->sole()->context['source'], DoctorWorkspaceEvent::where('action', 'booked')->sole()->context['follow_up_of_id']]);
        $this->actingAs($this->doctor)->get(route('doctor.appointments.show', $this->original))->assertDontSee('Book follow-up')->assertSee($followUp->reference());
        $this->actingAs($this->doctor)->get(route('doctor.appointments.show', $followUp))->assertSee('Follow-up to')->assertSee($this->original->reference());
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.appointments.show', $followUp))->assertOk()->assertSee('Follow-up to')->assertSee(route('admin.appointments.show', $this->original), false);
    }

    public function test_staff_cannot_link_another_doctors_or_another_patients_appointment(): void
    {
        [$otherDoctor, $otherProfile] = $this->doctorWithProfile('Dr. Other');
        $theirOriginal = $this->appointment($otherProfile, $this->patient, '2026-10-24 09:00', 'completed');
        $otherPatient = User::factory()->create(['role' => 'patient']);
        $this->appointment($this->profile, $otherPatient, '2026-10-25 09:00', 'completed');
        $slot = $this->slot($this->profile, '2026-11-09 09:00');

        // Another doctor's appointment id in the URL is not shown as a follow-up.
        $this->actingAs($this->doctor)->get(route('doctor.appointments.create', ['follow_up' => $theirOriginal->id]))->assertOk()->assertDontSee('data-follow-up-banner', false);

        // A forged follow_up_of_id is refused: another doctor's appointment, or the right appointment for the wrong patient.
        $this->actingAs($this->doctor)->post(route('doctor.appointments.store'), ['patient_id' => $this->patient->id, 'slot_id' => $slot->id, 'consultation_type' => 'physical', 'follow_up_of_id' => $theirOriginal->id])
            ->assertSessionHasErrors('follow_up_of_id');
        $this->actingAs($this->doctor)->post(route('doctor.appointments.store'), ['patient_id' => $otherPatient->id, 'slot_id' => $slot->id, 'consultation_type' => 'physical', 'follow_up_of_id' => $this->original->id])
            ->assertSessionHasErrors('follow_up_of_id');
        $this->assertSame('available', $slot->fresh()->status);

        $this->actingAs($otherDoctor)->get(route('doctor.appointments.show', $this->original))->assertForbidden();
    }

    public function test_an_assigned_assistant_can_book_a_follow_up_in_the_doctors_workspace(): void
    {
        $assistant = User::factory()->create();
        $assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $this->profile->assistants()->attach($assistant->id);
        $slot = $this->slot($this->profile, '2026-11-09 09:00');

        $this->actingAs($assistant)->get(route('assistant.workspace.appointments.show', [$this->profile, $this->original]))->assertOk()
            ->assertSee(route('assistant.workspace.appointments.create', [$this->profile, 'follow_up' => $this->original->id]), false);
        $this->actingAs($assistant)->post(route('assistant.workspace.appointments.store', $this->profile), [
            'patient_id' => $this->patient->id, 'slot_id' => $slot->id, 'consultation_type' => 'physical', 'follow_up_of_id' => $this->original->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->original->id, Appointment::where('appointment_slot_id', $slot->id)->sole()->follow_up_of_id);
        $this->assertSame([$assistant->id, 'assistant'], [DoctorWorkspaceEvent::where('action', 'booked')->sole()->actor_id, DoctorWorkspaceEvent::where('action', 'booked')->sole()->actor_role]);
    }

    public function test_rescheduling_keeps_the_link_and_completed_follow_ups_can_be_followed_up_again(): void
    {
        $booking = app(AppointmentBookingService::class);
        $followUp = $booking->book($this->patient, $this->slot($this->profile, '2026-11-09 09:00')->id, 'physical', $this->original->id);
        app(DoctorAppointmentService::class)->transition($this->doctor, $followUp, 'confirmed');
        app(DoctorAppointmentService::class)->reschedule($this->doctor, $followUp->fresh(), $this->slot($this->profile, '2026-11-10 09:00')->id);
        $this->assertSame($this->original->id, $followUp->fresh()->follow_up_of_id);

        $followUp->fresh()->update(['status' => 'completed']);
        $second = $booking->book($this->patient, $this->slot($this->profile, '2026-11-16 09:00')->id, 'physical', $followUp->id);

        $this->assertSame($followUp->id, $second->follow_up_of_id, 'Each follow-up stores its direct original.');
        $this->actingAs($this->patient)->get(route('patient.appointments.show', $followUp))->assertOk()
            ->assertSee($this->original->reference())->assertSee($second->reference());
    }

    private function expectRefusal(callable $booking, string $errorKey): void
    {
        try {
            $booking();
            $this->fail('The booking was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
    }

    /**
     * @return array{0: User, 1: DoctorProfile}
     */
    private function doctorWithProfile(string $name): array
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name]);
        $profile = DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'Physician', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 50, 'is_accepting_patients' => true]);

        return [$doctor->load('doctorProfile'), $profile];
    }

    private function slot(DoctorProfile $profile, string $startsAt, string $status = 'available'): AppointmentSlot
    {
        $start = CarbonImmutable::parse($startsAt, config('app.timezone'));

        return AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(10), 'status' => $status]);
    }

    private function appointment(DoctorProfile $profile, User $patient, string $startsAt, string $status): Appointment
    {
        return Appointment::create([
            'appointment_slot_id' => $this->slot($profile, $startsAt, 'booked')->id,
            'patient_id' => $patient->id,
            'status' => $status,
            'consultation_type' => 'physical',
        ]);
    }
}
