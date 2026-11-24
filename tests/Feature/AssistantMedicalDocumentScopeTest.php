<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\MedicalDocument;
use App\Models\MedicalDocumentEvent;
use App\Models\User;
use App\Services\MedicalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An assistant assigned to several doctors reaches a patient's documents only inside the workspace of a doctor
 * that patient is in the care of. The doctor in the workspace URL is the only doctor context; the shared
 * /medical-documents routes refuse assistants, so changing a document id cannot cross into another doctor's patients.
 */
class AssistantMedicalDocumentScopeTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIONS = ['download', 'preview'];

    private DoctorProfile $profileA;

    private DoctorProfile $profileB;

    private DoctorProfile $profileC;

    private User $assistant;

    private User $patientOne;

    private User $patientTwo;

    private User $patientThree;

    private MedicalDocument $documentOne;

    private MedicalDocument $documentTwo;

    private MedicalDocument $documentThree;

    private int $slotOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MedicalDocumentService::DISK);

        $this->profileA = $this->doctor('Dr. Alpha');
        $this->profileB = $this->doctor('Dr. Beta');
        $this->profileC = $this->doctor('Dr. Gamma');
        $this->assistant = User::factory()->create();
        $this->assistant->forceFill(['role' => User::ROLE_ASSISTANT])->save();
        $this->profileA->assistants()->attach($this->assistant->id);
        $this->profileB->assistants()->attach($this->assistant->id);

        $this->patientOne = $this->patientInCareOf($this->profileA, 'confirmed');
        $this->patientTwo = $this->patientInCareOf($this->profileB, 'booked');
        $this->patientThree = $this->patientInCareOf($this->profileC, 'completed');
        $this->documentOne = $this->imageDocument($this->patientOne, 'one.png');
        $this->documentTwo = $this->imageDocument($this->patientTwo, 'two.png');
        $this->documentThree = $this->imageDocument($this->patientThree, 'three.png');
    }

    public function test_each_patients_documents_open_only_in_the_workspace_of_their_doctor(): void
    {
        foreach (self::ACTIONS as $action) {
            $this->assertWorkspaceStatus(200, $this->profileA, $this->documentOne, $action);
            $this->assertWorkspaceStatus(403, $this->profileA, $this->documentTwo, $action);
            $this->assertWorkspaceStatus(403, $this->profileA, $this->documentThree, $action);

            $this->assertWorkspaceStatus(200, $this->profileB, $this->documentTwo, $action);
            $this->assertWorkspaceStatus(403, $this->profileB, $this->documentOne, $action);

            $this->assertWorkspaceStatus(403, $this->profileC, $this->documentThree, $action);
            $this->assertWorkspaceStatus(403, $this->profileC, $this->documentOne, $action);
        }

        $this->actingAs($this->assistant)->get(route('assistant.workspace.medical-records.index', $this->profileA))->assertOk()
            ->assertSee('one.png')->assertDontSee('two.png')
            ->assertSee(route('assistant.workspace.medical-documents.download', [$this->profileA, $this->documentOne]), false)
            ->assertSee(route('assistant.workspace.medical-documents.preview', [$this->profileA, $this->documentOne]), false)
            ->assertDontSee(route('medical-documents.download', $this->documentOne).'"', false)
            ->assertDontSee(route('medical-documents.preview', $this->documentOne).'"', false);
        $this->actingAs($this->assistant)->get(route('assistant.workspace.medical-records.index', $this->profileB))->assertOk()
            ->assertSee(route('assistant.workspace.medical-documents.download', [$this->profileB, $this->documentTwo]), false)
            ->assertDontSee('one.png');
    }

    public function test_care_must_be_with_the_selected_doctor_not_just_any_assigned_doctor(): void
    {
        $cancelledWithA = Appointment::query()->findOrFail($this->appointment($this->profileA, $this->patientTwo, 'cancelled')->id);
        $linkedToA = $this->imageDocument($this->patientTwo, 'linked.png', $cancelledWithA);
        $pdf = app(MedicalDocumentService::class)
            ->store($this->patientOne, UploadedFile::fake()->createWithContent('report.pdf', "%PDF-1.4\n%%EOF\n"), ['category' => 'other'])->fresh();

        foreach (self::ACTIONS as $action) {
            $this->assertWorkspaceStatus(403, $this->profileA, $linkedToA, $action);
            $this->assertWorkspaceStatus(200, $this->profileB, $linkedToA, $action);
        }

        $this->assertWorkspaceStatus(200, $this->profileA, $pdf, 'download');
        $this->assertWorkspaceStatus(403, $this->profileA, $pdf, 'preview');
    }

    public function test_document_ids_query_strings_and_request_bodies_cannot_change_the_doctor_context(): void
    {
        foreach (self::ACTIONS as $action) {
            $route = 'assistant.workspace.medical-documents.'.$action;

            foreach (['doctor_profile_id', 'doctor', 'doctor_profile'] as $key) {
                $this->actingAs($this->assistant)->get(route($route, [$this->profileA, $this->documentTwo, $key => $this->profileB->id]))->assertForbidden();
                $this->actingAs($this->assistant)->get(route('medical-documents.'.$action, [$this->documentOne, $key => $this->profileA->id]))->assertForbidden();
                $this->actingAs($this->assistant)->call('GET', route('medical-documents.'.$action, $this->documentOne), [$key => $this->profileA->id])->assertForbidden();
            }

            $query = '?doctorProfile='.$this->profileB->getRouteKey();
            $this->actingAs($this->assistant)->get(route($route, [$this->profileA, $this->documentTwo]).$query)->assertForbidden();
            $this->actingAs($this->assistant)->get(route('medical-documents.'.$action, $this->documentOne).$query)->assertForbidden();
            $this->actingAs($this->assistant)->get("/assistant/doctors/{$this->profileA->id}/medical-documents/999999/{$action}")->assertNotFound();
            $this->actingAs($this->assistant)->get("/assistant/doctors/999999/medical-documents/{$this->documentOne->id}/{$action}")->assertNotFound();
        }

        $this->assertSame(0, MedicalDocumentEvent::query()->where('action', 'downloaded')->count());
    }

    public function test_shared_routes_refuse_assistants_while_patients_doctors_and_admins_keep_their_access(): void
    {
        $doctorA = $this->profileA->user;
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (self::ACTIONS as $action) {
            $shared = 'medical-documents.'.$action;

            $this->actingAs($this->assistant)->get(route($shared, $this->documentOne))->assertForbidden();
            $this->actingAs($this->assistant)->get(route($shared, $this->documentTwo))->assertForbidden();

            $this->actingAs($this->patientOne)->get(route($shared, $this->documentOne))->assertOk();
            $this->actingAs($this->patientOne)->get(route($shared, $this->documentTwo))->assertForbidden();

            $this->actingAs($doctorA)->get(route($shared, $this->documentOne))->assertOk();
            $this->actingAs($doctorA)->get(route($shared, $this->documentTwo))->assertForbidden();

            $this->actingAs($admin)->get(route($shared, $this->documentOne))->assertOk();
            $this->actingAs($admin)->get(route($shared, $this->documentThree))->assertOk();

            foreach ([$this->patientOne, $doctorA, $admin] as $otherRole) {
                $this->actingAs($otherRole)->get(route('assistant.workspace.medical-documents.'.$action, [$this->profileA, $this->documentOne]))->assertForbidden();
            }
        }

        $this->actingAs($doctorA)->get(route('doctor.medical-records.index'))->assertOk()
            ->assertSee(route('medical-documents.download', $this->documentOne), false)
            ->assertSee(route('medical-documents.preview', $this->documentOne), false);
        $this->actingAs($this->patientOne)->get(route('patient.documents.index'))->assertOk()
            ->assertSee(route('medical-documents.download', $this->documentOne), false);
    }

    public function test_unassignment_and_deactivation_take_effect_on_the_next_request(): void
    {
        $this->profileA->assistants()->detach($this->assistant->id);
        foreach (self::ACTIONS as $action) {
            $this->assertWorkspaceStatus(403, $this->profileA, $this->documentOne, $action);
            $this->assertWorkspaceStatus(200, $this->profileB, $this->documentTwo, $action);
        }

        $this->profileB->user->forceFill(['deactivated_at' => now()])->save();
        foreach (self::ACTIONS as $action) {
            $this->assertWorkspaceStatus(403, $this->profileB, $this->documentTwo, $action);
        }

        $this->profileA->assistants()->attach($this->assistant->id);
        $this->assertWorkspaceStatus(200, $this->profileA, $this->documentOne, 'download');

        $this->assistant->forceFill(['deactivated_at' => now()])->save();
        foreach (self::ACTIONS as $action) {
            $this->flushSession();
            $this->assertWorkspaceStatus(403, $this->profileA, $this->documentOne, $action);
        }
    }

    public function test_the_policy_rechecks_the_assignment_itself_without_relying_on_the_workspace_middleware(): void
    {
        foreach (['view', ...self::ACTIONS] as $ability) {
            $this->assertTrue($this->assistant->can($ability, [$this->documentOne, $this->profileA]));
            $this->assertFalse($this->assistant->can($ability, $this->documentOne), 'No doctor context.');
            $this->assertFalse($this->assistant->can($ability, [$this->documentOne, $this->profileB]), 'Another assigned doctor.');
            $this->assertFalse($this->assistant->can($ability, [$this->documentThree, $this->profileC]), 'Unassigned doctor.');
        }

        $this->profileA->assistants()->detach($this->assistant->id);
        $this->assertFalse($this->assistant->can('download', [$this->documentOne, $this->profileA]), 'Unassigned since.');

        $this->profileA->assistants()->attach($this->assistant->id);
        $this->profileA->user->forceFill(['deactivated_at' => now()])->save();
        $this->assertFalse($this->assistant->can('download', [$this->documentOne, $this->profileA]), 'Doctor deactivated.');

        $this->profileA->user->forceFill(['deactivated_at' => null])->save();
        $this->assistant->forceFill(['deactivated_at' => now()])->save();
        $this->assertFalse($this->assistant->can('download', [$this->documentOne, $this->profileA]), 'Assistant deactivated.');
    }

    public function test_workspace_downloads_are_audited_under_the_assistant_and_refusals_are_not(): void
    {
        $this->assertWorkspaceStatus(200, $this->profileA, $this->documentOne, 'download');
        $this->assertWorkspaceStatus(403, $this->profileA, $this->documentTwo, 'download');
        $this->actingAs($this->assistant)->get(route('medical-documents.download', $this->documentTwo))->assertForbidden();

        $event = MedicalDocumentEvent::query()->where('action', 'downloaded')->sole();
        $this->assertSame($this->assistant->id, $event->actor_id);
        $this->assertSame($this->patientOne->id, $event->patient_id);
        $this->assertSame($this->documentOne->id, $event->medical_document_id);
    }

    private function assertWorkspaceStatus(int $status, DoctorProfile $profile, MedicalDocument $document, string $action): void
    {
        $this->actingAs($this->assistant)
            ->get(route('assistant.workspace.medical-documents.'.$action, [$profile, $document]))
            ->assertStatus($status);
    }

    private function doctor(string $name): DoctorProfile
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name]);

        return DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);
    }

    private function patientInCareOf(DoctorProfile $profile, string $status): User
    {
        $patient = User::factory()->create(['role' => 'patient']);
        $this->appointment($profile, $patient, $status);

        return $patient;
    }

    private function appointment(DoctorProfile $profile, User $patient, string $status): Appointment
    {
        $startsAt = now()->startOfHour()->addDays(2)->addMinutes(10 * $this->slotOffset++);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $profile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(10),
            'status' => in_array($status, ['cancelled', 'rejected'], true) ? 'available' : 'booked',
        ]);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => $status, 'consultation_type' => 'physical']);
    }

    private function imageDocument(User $patient, string $name, ?Appointment $appointment = null): MedicalDocument
    {
        return app(MedicalDocumentService::class)
            ->store($patient, UploadedFile::fake()->createWithContent($name, $this->pngBytes()), ['category' => 'other', 'appointment_id' => $appointment?->id])
            ->fresh();
    }

    private function pngBytes(): string
    {
        $raw = '';
        for ($row = 0; $row < 8; $row++) {
            $raw .= "\0".str_repeat("\x16\x7d\x91", 8);
        }
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 8, 8, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
    }
}
