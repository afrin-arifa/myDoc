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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MedicalDocumentPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MedicalDocumentService::DISK);
    }

    public function test_owner_gets_an_inline_image_preview_with_safe_headers_and_no_audit_noise(): void
    {
        $patient = $this->patient();
        $image = $this->document($patient, 'scan.png', $this->pngBytes());

        $response = $this->actingAs($patient)->get(route('medical-documents.preview', $image))->assertOk();

        $this->assertSame($this->pngBytes(), $response->streamedContent());
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('scan', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertSame(['uploaded'], MedicalDocumentEvent::query()->pluck('action')->all(), 'Thumbnails do not flood the audit log.');
    }

    public function test_pdfs_are_never_previewed_inline_and_still_download_as_attachments(): void
    {
        $patient = $this->patient();
        $pdf = $this->document($patient, 'report.pdf', self::PDF);

        $this->actingAs($patient)->get(route('medical-documents.preview', $pdf))->assertForbidden();
        $download = $this->actingAs($patient)->get(route('medical-documents.download', $pdf))->assertOk();
        $this->assertStringStartsWith('attachment;', $download->headers->get('Content-Disposition'));
    }

    public function test_preview_follows_the_existing_document_authorization(): void
    {
        $owner = $this->patient();
        $image = $this->document($owner, 'xray.webp', $this->webpBytes());
        $caringDoctor = $this->doctor();
        $this->appointment($caringDoctor, $owner, 'confirmed');
        $formerDoctor = $this->doctor();
        $this->appointment($formerDoctor, $owner, 'cancelled');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($owner)->get(route('medical-documents.preview', $image))->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->actingAs($caringDoctor)->get(route('medical-documents.preview', $image))->assertOk();
        $this->actingAs($admin)->get(route('medical-documents.preview', $image))->assertOk();
        $this->actingAs($this->patient())->get(route('medical-documents.preview', $image))->assertForbidden();
        $this->actingAs($formerDoctor)->get(route('medical-documents.preview', $image))->assertForbidden();
        $this->actingAs($this->doctor())->get(route('medical-documents.preview', $image))->assertForbidden();

        $owner->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($owner->fresh())->get(route('medical-documents.preview', $image))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->get(route('medical-documents.preview', $image))->assertRedirect(route('login'));
    }

    public function test_tampered_or_missing_files_are_refused_without_leaking_details(): void
    {
        Log::spy();
        $patient = $this->patient();
        $tampered = $this->document($patient, 'photo.png', $this->pngBytes());
        Storage::disk(MedicalDocumentService::DISK)->put($tampered->storage_path, '<html><script>alert(1)</script></html>');
        $missing = $this->document($patient, 'gone.png', $this->pngBytes());
        Storage::disk(MedicalDocumentService::DISK)->delete($missing->storage_path);
        $outside = $this->document($patient, 'outside.png', $this->pngBytes());
        Storage::disk(MedicalDocumentService::DISK)->put('elsewhere.png', $this->pngBytes());
        $outside->forceFill(['storage_path' => '../elsewhere.png'])->save();

        $this->actingAs($patient)->get(route('medical-documents.preview', $tampered))->assertNotFound()->assertDontSee('alert(1)', false);
        $this->actingAs($patient)->get(route('medical-documents.preview', $missing))->assertNotFound();
        $this->actingAs($patient)->get(route('medical-documents.preview', $outside))->assertNotFound();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context === ['medical_document_id' => $tampered->id])->once();
    }

    public function test_records_page_shows_responsive_cards_thumbnails_and_a_pdf_placeholder(): void
    {
        $patient = $this->patient();
        $image = $this->document($patient, 'blood-test.png', $this->pngBytes(), ['title' => 'Blood test March']);
        $pdf = $this->document($patient, 'discharge-summary.pdf', self::PDF);

        $page = $this->actingAs($patient)->get(route('patient.documents.index'))->assertOk();

        $page->assertSee('row-cols-1 row-cols-md-2 row-cols-lg-3', false)
            ->assertSee('src="'.route('medical-documents.preview', $image).'"', false)
            ->assertDontSee(route('medical-documents.preview', $pdf), false)
            ->assertSee('fa-file-pdf', false)
            ->assertSee('Blood test March')->assertSee('blood-test.png')->assertSee('discharge-summary.pdf')
            ->assertSee('Imaging report')->assertSee('PNG · ')->assertSee('Not linked')
            ->assertSee(route('medical-documents.download', $pdf), false)
            ->assertSee(route('medical-documents.destroy', $pdf), false)
            ->assertSee('id="document-preview"', false)
            ->assertDontSee($image->storage_path)->assertDontSee($pdf->storage_path)
            ->assertDontSee('/storage/', false);
    }

    public function test_cards_show_the_linked_appointment_and_keep_filtering_and_pagination(): void
    {
        $patient = $this->patient();
        $doctor = $this->doctor('Dr. Card Linked');
        $appointment = $this->appointment($doctor, $patient, 'confirmed');
        $this->document($patient, 'linked.pdf', self::PDF, ['appointment_id' => $appointment->id, 'category' => 'prescription']);
        foreach (range(1, 12) as $number) {
            $this->document($patient, "lab-{$number}.pdf", self::PDF, ['category' => 'laboratory_report']);
        }

        $this->actingAs($patient)->get(route('patient.documents.index'))->assertOk()
            ->assertViewHas('documents', fn ($documents) => $documents->count() === 12 && $documents->total() === 13)
            ->assertSee('13 documents');
        $this->actingAs($patient)->get(route('patient.documents.index', ['page' => 2]))->assertOk()
            ->assertViewHas('documents', fn ($documents) => $documents->count() === 1);

        $this->actingAs($patient)->get(route('patient.documents.index', ['category' => 'prescription']))->assertOk()
            ->assertSee('linked.pdf')->assertDontSee('lab-1.pdf')->assertSee('Dr. Card Linked')
            ->assertSee($appointment->slot->starts_at->format('M j, Y'));
        $this->actingAs($patient)->get(route('patient.documents.index', ['category' => 'medical_certificate']))->assertOk()
            ->assertSee('No documents of this type')->assertSee('Show all documents');
    }

    public function test_empty_records_page_invites_the_first_upload(): void
    {
        $this->actingAs($this->patient())->get(route('patient.documents.index'))->assertOk()
            ->assertSee('No documents yet')->assertSee('Upload your first document')->assertSee('href="#upload"', false)
            ->assertSee('fa-folder-open', false)->assertDontSee('row-cols-lg-3', false);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function document(User $patient, string $name, string $content, array $details = []): MedicalDocument
    {
        return app(MedicalDocumentService::class)->store(
            $patient,
            UploadedFile::fake()->createWithContent($name, $content),
            ['category' => 'imaging_report', ...$details]
        )->fresh();
    }

    private function appointment(User $doctor, User $patient, string $status): Appointment
    {
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addMinutes(10),
            'status' => $status === 'cancelled' ? 'available' : 'booked',
        ]);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => $status, 'consultation_type' => 'physical']);
    }

    private function patient(): User
    {
        return User::factory()->create(['role' => 'patient']);
    }

    private function doctor(?string $name = null): User
    {
        $doctor = User::factory()->create(['role' => 'doctor', ...($name ? ['name' => $name] : [])]);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        return $doctor->load('doctorProfile');
    }

    private function pngBytes(int $width = 32, int $height = 32): string
    {
        $raw = '';
        for ($row = 0; $row < $height; $row++) {
            $raw .= "\0".str_repeat("\x16\x7d\x91", $width);
        }
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($raw)).$chunk('IEND', '');
    }

    /**
     * A minimal valid lossless WebP (1x1).
     */
    private function webpBytes(): string
    {
        return base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');
    }
}
