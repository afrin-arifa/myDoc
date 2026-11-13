<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\MedicalDocument;
use App\Models\User;
use App\Services\MedicalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 7: shared doctor workspace pages speak to a Doctor Assistant about the selected doctor, always show whose
 * workspace is open, and keep the doctor's own wording unchanged.
 */
class AssistantWorkspaceWordingTest extends TestCase
{
    use RefreshDatabase;

    private User $assistant;

    private User $doctor;

    private DoctorProfile $profile;

    private User $patient;

    private int $slotOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MedicalDocumentService::DISK);
        $this->doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr. Alpha']);
        $this->profile = DoctorProfile::create(['user_id' => $this->doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-1', 'consultation_fee' => 10]);
        $this->assistant = User::factory()->create(['name' => 'Asha Assistant']);
        $this->assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $this->profile->assistants()->attach($this->assistant->id);
        $this->patient = User::factory()->create(['role' => 'patient', 'name' => 'Pat Patient']);
    }

    public function test_list_pages_name_the_selected_doctor_instead_of_addressing_the_assistant_as_the_doctor(): void
    {
        $this->appointment('confirmed');

        $pages = [
            'appointments.index' => ['Appointments for Dr. Alpha', ['Appointment management', 'Review your schedule']],
            'patients.index' => ['Patients of Dr. Alpha', ['My patients', 'Search your patients', 'appointment with you']],
            'medical-records.index' => ['appointment with Dr. Alpha', ['appointment with you']],
            'schedule' => ["Dr. Alpha's schedule", ['My Schedule', 'Manage your availability']],
        ];

        foreach ($pages as $route => [$expected, $doctorWording]) {
            $response = $this->actingAs($this->assistant)->get(route('assistant.workspace.'.$route, $this->profile))->assertOk()
                ->assertSee($expected, false)->assertSee('Working for')->assertSee('data-selected-doctor>Dr. Alpha', false)
                ->assertSee(route('assistant.account'), false);
            foreach ($doctorWording as $text) {
                $response->assertDontSee($text, false);
            }
        }
    }

    public function test_pages_without_the_sidebar_show_the_selected_doctor_and_a_way_to_switch(): void
    {
        $appointment = $this->appointment('booked');
        $this->slot();

        foreach ([
            route('assistant.workspace.appointments.show', [$this->profile, $appointment]),
            route('assistant.workspace.appointments.create', $this->profile),
            route('assistant.workspace.appointments.reschedule', [$this->profile, $appointment]),
        ] as $url) {
            $this->actingAs($this->assistant)->get($url)->assertOk()
                ->assertSee('data-workspace-banner', false)->assertSee('data-selected-doctor>Dr. Alpha', false)
                ->assertSee('Asha Assistant')->assertSee(route('assistant.doctors.index'), false)->assertDontSee('Doctor space');
        }

        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.create', $this->profile))
            ->assertSee('Only patients who have booked with Dr. Alpha before are listed.');
    }

    public function test_doctor_pages_keep_their_own_wording_without_the_assistant_banner(): void
    {
        $appointment = $this->appointment('booked');

        $this->actingAs($this->doctor)->get(route('doctor.appointments.index'))->assertOk()->assertSee('Appointment management')->assertDontSee('Appointments for');
        $this->actingAs($this->doctor)->get(route('doctor.patients.index'))->assertOk()->assertSee('My patients')->assertSee('Search your patients');
        $this->actingAs($this->doctor)->get(route('doctor.schedule'))->assertOk()->assertSee('My Schedule');
        foreach ([route('doctor.appointments.show', $appointment), route('doctor.appointments.create'), route('doctor.appointments.reschedule', $appointment)] as $url) {
            $this->actingAs($this->doctor)->get($url)->assertOk()->assertDontSee('data-workspace-banner', false)->assertDontSee('Working for');
        }
        $this->actingAs($this->doctor)->get(route('doctor.appointments.show', $appointment))->assertSee('Doctor space');
    }

    public function test_a_medical_record_links_its_appointment_inside_the_assistant_workspace(): void
    {
        $appointment = $this->appointment('confirmed');
        DB::transaction(fn () => app(MedicalDocumentService::class)->store(
            $this->patient,
            UploadedFile::fake()->createWithContent('linked-report.pdf', "%PDF-1.4\n%%EOF\n"),
            ['category' => 'other', 'appointment_id' => $appointment->id],
        ));
        $this->assertSame($appointment->id, MedicalDocument::sole()->appointment_id);

        $assistantLink = route('assistant.workspace.appointments.show', [$this->profile, $appointment]);
        $this->actingAs($this->assistant)->get(route('assistant.workspace.medical-records.index', $this->profile))->assertOk()
            ->assertSee('href="'.$assistantLink.'"', false)
            ->assertDontSee('href="'.route('doctor.appointments.show', $appointment).'"', false);
        $this->actingAs($this->assistant)->get($assistantLink)->assertOk();

        $this->flushSession();
        $this->actingAs($this->doctor)->get(route('doctor.medical-records.index'))->assertOk()
            ->assertSee('href="'.route('doctor.appointments.show', $appointment).'"', false);
    }

    public function test_rescheduling_without_free_slots_explains_what_to_do(): void
    {
        $appointment = $this->appointment('confirmed');

        $this->actingAs($this->assistant)->get(route('assistant.workspace.appointments.reschedule', [$this->profile, $appointment]))->assertOk()
            ->assertSee('No available slots')->assertSee(route('assistant.workspace.schedule', $this->profile), false)
            ->assertDontSee('Confirm reschedule');
    }

    private function slot(string $status = 'available'): AppointmentSlot
    {
        $startsAt = now()->startOfHour()->addDays(3)->addMinutes(10 * $this->slotOffset++);

        return AppointmentSlot::create(['doctor_profile_id' => $this->profile->id, 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(10), 'status' => $status]);
    }

    private function appointment(string $status): Appointment
    {
        return Appointment::create(['appointment_slot_id' => $this->slot('booked')->id, 'patient_id' => $this->patient->id, 'status' => $status, 'consultation_type' => 'physical']);
    }
}
