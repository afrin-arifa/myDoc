<?php

namespace Tests\Feature;

use App\Http\Controllers\AppointmentController;
use App\Models\Appointment;
use App\Models\AppointmentNotification;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AssertsUnavailableSlotRedirect;
use Tests\TestCase;

/**
 * Release 1, Phase 1 booking corrections: the admin status filter, booking double-submits, pending-approval
 * wording, shared status labels, the patient cancellation window and voice/video availability.
 */
class BookingCorrectionsTest extends TestCase
{
    use AssertsUnavailableSlotRedirect, RefreshDatabase;

    private User $doctor;

    private User $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr. Correction']);
        DoctorProfile::create([
            'user_id' => $this->doctor->id,
            'professional_title' => 'Physician',
            'license_number' => 'LIC-'.$this->doctor->id,
            'consultation_fee' => 50,
            'is_accepting_patients' => true,
        ]);
        $this->doctor->load('doctorProfile');
        $this->patient = User::factory()->create(['role' => 'patient']);
    }

    public function test_admin_can_filter_appointments_by_every_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (array_keys(Appointment::STATUS_LABELS) as $index => $status) {
            $this->appointment(CarbonImmutable::now()->addDays($index + 2), $status, User::factory()->create(['role' => 'patient', 'name' => "Patient {$status}"]));
        }

        foreach (Appointment::STATUS_LABELS as $status => $label) {
            $response = $this->actingAs($admin)->get(route('admin.appointments.index', ['status' => $status]))->assertOk();
            $response->assertSee("Patient {$status}")->assertSee($label);

            foreach (array_diff(array_keys(Appointment::STATUS_LABELS), [$status]) as $other) {
                $response->assertDontSee("Patient {$other}");
            }
        }
    }

    public function test_a_second_submit_by_the_same_patient_returns_the_existing_request_instead_of_an_error(): void
    {
        $slot = $this->slot(CarbonImmutable::now()->addDays(3));

        $this->actingAs($this->patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])
            ->assertRedirect(route('patient.appointments.index'))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'request has been sent') && str_contains($message, 'pending'));

        $this->actingAs($this->patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])
            ->assertRedirect(route('patient.appointments.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'already requested'));

        $this->assertSame(1, Appointment::count());
        $this->assertSame(['booked', 'booked:patient'], AppointmentNotification::query()->where('type', AppointmentNotification::TYPE_BOOKED)->orderBy('id')->pluck('transition_key')->all(), 'The second submit records no extra notifications.');

        $otherPatient = User::factory()->create(['role' => 'patient']);
        $this->assertSentToChooseAnotherTime($this->actingAs($otherPatient)->from(route('appointment-slots.confirm', $slot))
            ->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical']), $slot);
        $this->assertSame(1, Appointment::count());
    }

    public function test_the_booking_page_describes_a_request_and_guards_against_double_submits(): void
    {
        $slot = $this->slot(CarbonImmutable::now()->addDays(3));

        $this->actingAs($this->patient)->get(route('appointment-slots.confirm', $slot))->assertOk()
            ->assertSee('data-disable-on-submit', false)
            ->assertSee('Send appointment request')
            ->assertSee('pending approval')
            ->assertDontSee('Confirm appointment');
    }

    public function test_every_page_uses_the_shared_status_labels(): void
    {
        $appointment = $this->appointment(CarbonImmutable::now()->addDays(3), 'booked');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertSame('Pending approval', Appointment::statusLabel('booked'));
        $this->assertSame('Available', Appointment::statusLabel('available'));

        foreach ([
            [$this->patient, route('patient.appointments.show', $appointment)],
            [$this->patient, route('patient.appointments.index')],
            [$this->patient, route('patient.dashboard')],
            [$this->doctor, route('doctor.appointments.show', $appointment)],
            [$this->doctor, route('doctor.dashboard')],
            [$admin, route('admin.appointments.index')],
            [$admin, route('admin.appointments.show', $appointment)],
            [$admin, route('admin.dashboard')],
        ] as [$user, $url]) {
            $this->flushSession();
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('Pending approval')
                ->assertDontSee('Awaiting confirmation')
                ->assertDontSee('>Booked<', false);
        }
    }

    public function test_patients_only_see_the_cancel_button_before_the_cancellation_deadline(): void
    {
        $early = $this->appointment(CarbonImmutable::now()->addDays(3), 'confirmed');
        $late = $this->appointment(CarbonImmutable::now()->addHours(5), 'booked');
        $past = $this->appointment(CarbonImmutable::now()->subDay(), 'completed');

        $this->assertTrue($early->isCancellableByPatient());
        $this->assertFalse($late->isCancellableByPatient());
        $this->assertFalse($past->isCancellableByPatient());

        $this->actingAs($this->patient)->get(route('patient.appointments.show', $early))->assertOk()
            ->assertSee(route('appointments.cancel', $early), false)
            ->assertSee('You can cancel online until');

        $this->actingAs($this->patient)->get(route('patient.appointments.show', $late))->assertOk()
            ->assertDontSee(route('appointments.cancel', $late), false)
            ->assertSee('Online cancellation closes 24 hours before the appointment starts');

        $this->actingAs($this->patient)->get(route('patient.appointments.show', $past))->assertOk()
            ->assertDontSee(route('appointments.cancel', $past), false)
            ->assertDontSee('Online cancellation closes');

        $this->actingAs($this->patient)->get(route('patient.appointments.index'))->assertOk()
            ->assertSee(route('appointments.cancel', $early), false)
            ->assertDontSee(route('appointments.cancel', $late), false)
            ->assertSee('Online cancellation closes 24 hours before the start.');

        $this->actingAs($this->patient)->post(route('appointments.cancel', $late))->assertSessionHasErrors('appointment');
        $this->assertSame('booked', $late->fresh()->status);
    }

    public function test_voice_and_video_cannot_be_booked_while_remote_consultations_are_disabled(): void
    {
        $slot = $this->slot(CarbonImmutable::now()->addDays(3));

        $this->actingAs($this->patient)->get(route('appointment-slots.confirm', $slot))->assertOk()
            ->assertSee('value="physical"', false)
            ->assertDontSee('value="voice"', false)
            ->assertDontSee('value="video"', false)
            ->assertSee('Voice and video consultations are not available yet.');

        foreach (['voice', 'video'] as $type) {
            $this->actingAs($this->patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => $type])
                ->assertSessionHasErrors('consultation_type');
            $this->actingAs($this->doctor)->post(route('doctor.appointments.store'), [
                'patient_email' => $this->patient->email, 'slot_id' => $slot->id, 'consultation_type' => $type,
            ])->assertSessionHasErrors('consultation_type');
        }
        $this->assertSame(0, Appointment::count());
        $this->assertSame('available', $slot->fresh()->status);

        $this->actingAs($this->doctor)->get(route('doctor.appointments.create'))->assertOk()
            ->assertSee('<option value="physical"', false)
            ->assertDontSee('<option value="video"', false);

        $this->actingAs($this->patient)->post(route('appointment-slots.book', $slot), ['consultation_type' => 'physical'])
            ->assertSessionHasNoErrors();
        $this->assertSame('physical', Appointment::sole()->consultation_type);
    }

    public function test_existing_remote_appointments_are_preserved_but_offer_no_call_actions(): void
    {
        $now = CarbonImmutable::now();
        $video = $this->appointment($now->addMinutes(5), 'confirmed', type: 'video');

        $this->actingAs($this->patient)->get(route('patient.appointments.show', $video))->assertOk()
            ->assertSee('Video call')
            ->assertSee('Voice and video calls are not available in myDoc yet')
            ->assertDontSee('when a provider is configured');

        $this->assertFalse($video->canStartConsultation());
        $this->actingAs($this->doctor)->get(route('doctor.appointments.show', $video))->assertOk()
            ->assertDontSee(route('doctor.appointments.start', $video), false);
        $this->actingAs($this->doctor)->get(route('doctor.appointments.index'))->assertOk()
            ->assertDontSee(route('doctor.appointments.start', $video), false);

        config(['booking.remote_consultations_enabled' => true]);
        $this->assertTrue($video->canStartConsultation());
        $this->actingAs($this->doctor)->get(route('doctor.appointments.show', $video))->assertOk()
            ->assertSee(route('doctor.appointments.start', $video), false);
    }

    public function test_staff_bookings_report_that_they_are_pending_approval(): void
    {
        $slot = $this->slot(CarbonImmutable::now()->addDays(3));

        $this->actingAs($this->doctor)->post(route('doctor.appointments.store'), [
            'patient_email' => $this->patient->email, 'slot_id' => $slot->id, 'consultation_type' => 'physical',
        ])->assertSessionHas('status', 'Appointment created. It is pending approval until it is confirmed.');
        $this->assertSame('booked', Appointment::sole()->status);
    }

    public function test_the_unrouted_doctor_index_action_was_removed(): void
    {
        $this->assertFalse(method_exists(AppointmentController::class, 'doctorIndex'));
    }

    private function slot(CarbonImmutable $startsAt, string $status = 'available'): AppointmentSlot
    {
        return AppointmentSlot::create([
            'doctor_profile_id' => $this->doctor->doctorProfile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addMinutes(10),
            'status' => $status,
        ]);
    }

    private function appointment(CarbonImmutable $startsAt, string $status, ?User $patient = null, string $type = 'physical'): Appointment
    {
        $slot = $this->slot($startsAt, in_array($status, Appointment::ACTIVE_STATUSES, true) || $status === 'completed' ? 'booked' : 'available');

        return Appointment::create([
            'appointment_slot_id' => $slot->id,
            'patient_id' => ($patient ?? $this->patient)->id,
            'status' => $status,
            'consultation_type' => $type,
            'booked_at' => now(),
        ]);
    }
}
