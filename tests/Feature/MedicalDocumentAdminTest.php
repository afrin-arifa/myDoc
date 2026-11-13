<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\MedicalDocument;
use App\Models\MedicalDocumentEvent;
use App\Models\User;
use App\Services\MedicalDocumentService;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MedicalDocumentAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MedicalDocumentService::DISK);
    }

    public function test_admin_sees_platform_wide_documents_with_search_and_filters(): void
    {
        $admin = $this->admin();
        $doctor = $this->doctor('Dr. Searchable');
        $alice = $this->patient('Alice Admin-View');
        $bob = $this->patient('Bob Admin-View');
        $appointment = $this->appointment($doctor, $alice);
        $this->document($alice, ['title' => 'Alice prescription', 'category' => 'prescription', 'appointment_id' => $appointment->id], 'alice-rx.pdf');
        $old = $this->document($bob, ['title' => 'Bob certificate', 'category' => 'medical_certificate'], 'bob-cert.pdf');
        $old->forceFill(['created_at' => now()->subDays(10)])->save();

        $titles = fn (array $query) => $this->actingAs($admin)->get(route('admin.medical-documents.index', $query))->assertOk()
            ->viewData('documents')->pluck('title')->sort()->values()->all();

        $this->assertSame(['Alice prescription', 'Bob certificate'], $titles([]));
        $this->assertSame(['Alice prescription'], $titles(['search' => 'Alice Admin']));
        $this->assertSame(['Bob certificate'], $titles(['search' => 'bob-cert']));
        $this->assertSame(['Alice prescription'], $titles(['search' => 'Dr. Searchable']));
        $this->assertSame(['Bob certificate'], $titles(['search' => 'certificate']));
        $this->assertSame(['Bob certificate'], $titles(['category' => 'medical_certificate']));
        $this->assertSame(['Alice prescription'], $titles(['from' => now()->subDay()->toDateString()]));
        $this->assertSame(['Bob certificate'], $titles(['to' => now()->subDays(5)->toDateString()]));
        $this->actingAs($admin)->get(route('admin.medical-documents.index', ['from' => '2026-12-01', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
        $this->actingAs($admin)->get(route('admin.medical-documents.index', ['search' => 'nothing-matches']))->assertSee('No documents found');
    }

    public function test_admin_can_view_details_download_and_delete_with_an_audit_trail(): void
    {
        $admin = $this->admin();
        $patient = $this->patient('Audited Patient');
        $document = $this->document($patient, ['title' => 'Audited report', 'description' => 'Sensitive symptom note']);

        $this->actingAs($admin)->get(route('admin.medical-documents.show', $document))->assertOk()
            ->assertSee('Audited Patient')->assertSee('Uploaded')->assertDontSee($document->storage_path);
        $download = $this->actingAs($admin)->get(route('medical-documents.download', $document))->assertOk();
        $this->assertSame(self::PDF, $download->streamedContent());

        $this->actingAs($admin)->delete(route('medical-documents.destroy', $document))->assertRedirect(route('admin.medical-documents.index'));

        $this->assertModelMissing($document);
        Storage::disk(MedicalDocumentService::DISK)->assertMissing($document->storage_path);
        $this->assertSame(
            ['uploaded', 'downloaded', 'deleted'],
            MedicalDocumentEvent::query()->orderBy('id')->pluck('action')->all()
        );
        $events = MedicalDocumentEvent::all();
        $this->assertTrue($events->every(fn ($event) => $event->patient_id === $patient->id));
        $this->assertSame([$patient->id, $admin->id, $admin->id], $events->pluck('actor_id')->all());
        foreach ($events as $event) {
            $this->assertNull($event->medical_document_id, 'Audit rows survive deletion.');
            $this->assertStringNotContainsString('Sensitive', json_encode($event->toArray()));
            $this->assertStringNotContainsString('medical-documents/', json_encode($event->toArray()));
        }
    }

    public function test_patients_doctors_and_guests_cannot_use_admin_document_management(): void
    {
        $patient = $this->patient();
        $document = $this->document($patient);
        $doctor = $this->doctor();
        $this->appointment($doctor, $patient);

        foreach ([$patient, $doctor] as $user) {
            $this->actingAs($user)->get(route('admin.medical-documents.index'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.medical-documents.show', $document))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->get(route('admin.medical-documents.index'))->assertRedirect(route('login'));

        $deactivatedAdmin = $this->admin();
        $deactivatedAdmin->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($deactivatedAdmin->fresh())->get(route('admin.medical-documents.index'))->assertForbidden();
        $this->actingAs($deactivatedAdmin->fresh())->get(route('medical-documents.download', $document))->assertForbidden();
        $this->assertFalse($deactivatedAdmin->fresh()->can('delete', $document));
    }

    public function test_service_rejects_disguised_content_even_when_called_directly(): void
    {
        $patient = $this->patient();

        foreach (['x.pdf' => '<html><script>alert(1)</script></html>', 'x.png' => '<?php echo 1; ?>', 'x.svg' => '<svg xmlns="http://www.w3.org/2000/svg"/>'] as $name => $content) {
            try {
                app(MedicalDocumentService::class)->store($patient, UploadedFile::fake()->createWithContent($name, $content), ['category' => 'other']);
                $this->fail("{$name} must be rejected.");
            } catch (RuntimeException) {
            }
        }

        $this->assertSame(0, MedicalDocument::count());
        $this->assertSame([], Storage::disk(MedicalDocumentService::DISK)->allFiles());
    }

    public function test_deleting_never_touches_another_documents_file_or_paths_outside_the_document_store(): void
    {
        $patient = $this->patient();
        $first = $this->document($patient);
        $second = $this->document($patient);
        Storage::disk(MedicalDocumentService::DISK)->put('doctor-photos/keep.png', 'photo');
        Storage::disk(MedicalDocumentService::DISK)->put('unrelated.txt', 'keep');

        $this->assertNotSame($first->storage_path, $second->storage_path);
        $tampered = $this->document($patient);
        $tamperedPath = $tampered->storage_path;
        $tampered->forceFill(['storage_path' => '../unrelated.txt'])->save();

        app(MedicalDocumentService::class)->delete($tampered->fresh(), $this->admin());
        app(MedicalDocumentService::class)->delete($first, $this->admin());

        Storage::disk(MedicalDocumentService::DISK)->assertMissing($first->storage_path);
        Storage::disk(MedicalDocumentService::DISK)->assertExists($second->storage_path);
        Storage::disk(MedicalDocumentService::DISK)->assertExists('unrelated.txt');
        Storage::disk(MedicalDocumentService::DISK)->assertExists('doctor-photos/keep.png');
        // A tampered path is ignored rather than followed, so the document's real file is simply left in place.
        Storage::disk(MedicalDocumentService::DISK)->assertExists($tamperedPath);
    }

    public function test_logs_never_contain_document_contents_descriptions_or_paths(): void
    {
        Log::spy();
        $patient = $this->patient();
        $document = $this->document($patient, ['description' => 'Private diagnosis text']);
        Storage::disk(MedicalDocumentService::DISK)->delete($document->storage_path);

        $this->actingAs($patient)->get(route('medical-documents.download', $document))->assertRedirect();

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($document): bool {
            return $message === 'Medical document file is missing.'
                && $context === ['medical_document_id' => $document->id];
        })->once();
    }

    public function test_navigation_links_reach_each_roles_document_page(): void
    {
        $this->actingAs($this->patient())->get(route('patient.dashboard'))->assertOk()->assertSee(route('patient.documents.index'), false);
        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.medical-documents.index'), false);
    }

    public function test_hidden_metadata_is_not_serialized(): void
    {
        $document = $this->document($this->patient());

        $this->assertArrayNotHasKey('storage_path', $document->toArray());
        $this->expectException(MassAssignmentException::class);
        MedicalDocument::create(['patient_id' => 1, 'storage_path' => 'x']);
    }

    private function document(User $patient, array $details = [], string $name = 'report.pdf'): MedicalDocument
    {
        return app(MedicalDocumentService::class)->store(
            $patient,
            UploadedFile::fake()->createWithContent($name, self::PDF),
            ['category' => 'laboratory_report', ...$details]
        )->fresh();
    }

    private function appointment(User $doctor, User $patient): Appointment
    {
        $slot = AppointmentSlot::create(['doctor_profile_id' => $doctor->doctorProfile->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(10), 'status' => 'booked']);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => 'confirmed', 'consultation_type' => 'physical']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function patient(?string $name = null): User
    {
        return User::factory()->create(['role' => 'patient', ...($name ? ['name' => $name] : [])]);
    }

    private function doctor(?string $name = null): User
    {
        $doctor = User::factory()->create(['role' => 'doctor', ...($name ? ['name' => $name] : [])]);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        return $doctor->load('doctorProfile');
    }
}
