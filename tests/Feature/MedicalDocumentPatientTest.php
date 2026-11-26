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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MedicalDocumentPatientTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MedicalDocumentService::DISK);
    }

    public function test_patient_can_upload_a_pdf_and_image_to_private_storage_with_random_names(): void
    {
        $patient = $this->patient();
        $appointment = $this->appointment($patient);

        $this->actingAs($patient)->post(route('patient.documents.store'), [
            'document' => UploadedFile::fake()->createWithContent('My Blood Test.pdf', self::PDF),
            'category' => 'laboratory_report',
            'title' => 'Blood test',
            'description' => 'Fasting sample',
            'appointment_id' => $appointment->id,
        ])->assertRedirect(route('patient.documents.index'))->assertSessionHasNoErrors();

        $this->actingAs($patient)->post(route('patient.documents.store'), [
            'document' => UploadedFile::fake()->createWithContent('scan.png', $this->pngBytes()),
            'category' => 'imaging_report',
        ])->assertSessionHasNoErrors();

        [$pdf, $png] = MedicalDocument::query()->orderBy('id')->get();
        $this->assertSame([$patient->id, $patient->id], [$pdf->patient_id, $png->patient_id]);
        $this->assertSame($patient->id, $pdf->uploaded_by);
        $this->assertSame($appointment->id, $pdf->appointment_id);
        $this->assertSame('My Blood Test.pdf', $pdf->original_name);
        $this->assertSame('application/pdf', $pdf->mime_type);
        $this->assertSame(strlen(self::PDF), $pdf->size);
        $this->assertSame('image/png', $png->mime_type);
        $this->assertNull($png->appointment_id);

        foreach ([$pdf, $png] as $document) {
            $this->assertMatchesRegularExpression('#^medical-documents/'.$patient->id.'/[0-9a-f-]{36}\.(pdf|png)$#', $document->storage_path);
            $this->assertStringNotContainsString('Blood', $document->storage_path);
            Storage::disk(MedicalDocumentService::DISK)->assertExists($document->storage_path);
        }
        $this->assertSame('local', MedicalDocumentService::DISK);
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
        $this->assertSame(2, MedicalDocumentEvent::where('action', 'uploaded')->where('actor_id', $patient->id)->count());
    }

    public function test_patient_sees_and_downloads_only_their_own_documents(): void
    {
        $patient = $this->patient('Owner Patient');
        $other = $this->patient('Other Patient');
        $own = $this->document($patient, ['title' => 'My prescription']);
        $theirs = $this->document($other, ['title' => 'Their secret report']);

        $this->actingAs($patient)->get(route('patient.documents.index'))->assertOk()
            ->assertSee('My prescription')->assertDontSee('Their secret report')
            ->assertDontSee($own->storage_path);

        $download = $this->actingAs($patient)->get(route('medical-documents.download', $own))->assertOk();
        $this->assertSame(self::PDF, $download->streamedContent());
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('report.pdf', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertSame(1, MedicalDocumentEvent::where('action', 'downloaded')->where('medical_document_id', $own->id)->count());

        $denied = $this->actingAs($patient)->get(route('medical-documents.download', $theirs))->assertForbidden();
        $this->assertStringNotContainsString(self::PDF, (string) $denied->getContent());
        $this->actingAs($patient)->delete(route('medical-documents.destroy', $theirs))->assertForbidden();
        $this->assertModelExists($theirs);
        Storage::disk(MedicalDocumentService::DISK)->assertExists($theirs->storage_path);
    }

    public function test_patient_can_delete_their_document_and_its_file_only(): void
    {
        $patient = $this->patient();
        $keep = $this->document($patient);
        $remove = $this->document($patient);

        $this->actingAs($patient)->delete(route('medical-documents.destroy', $remove))->assertRedirect(route('patient.documents.index'));

        $this->assertModelMissing($remove);
        Storage::disk(MedicalDocumentService::DISK)->assertMissing($remove->storage_path);
        Storage::disk(MedicalDocumentService::DISK)->assertExists($keep->storage_path);
        $event = MedicalDocumentEvent::where('action', 'deleted')->firstOrFail();
        $this->assertNull($event->medical_document_id, 'The audit entry outlives the document.');
        $this->assertSame($patient->id, $event->patient_id);
    }

    public function test_ownership_and_metadata_cannot_be_injected(): void
    {
        $patient = $this->patient();
        $victim = $this->patient();
        $victimAppointment = $this->appointment($victim);

        $this->actingAs($patient)->post(route('patient.documents.store'), [
            'document' => UploadedFile::fake()->createWithContent('a.pdf', self::PDF),
            'category' => 'prescription',
            'patient_id' => $victim->id,
            'uploaded_by' => $victim->id,
            'storage_path' => '../../.env',
            'mime_type' => 'text/html',
            'size' => 1,
            'original_name' => '../../evil.php',
        ])->assertSessionHasNoErrors();

        $document = MedicalDocument::firstOrFail();
        $this->assertSame($patient->id, $document->patient_id);
        $this->assertSame($patient->id, $document->uploaded_by);
        $this->assertStringStartsWith('medical-documents/'.$patient->id.'/', $document->storage_path);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame(strlen(self::PDF), $document->size);
        $this->assertSame('a.pdf', $document->original_name);

        $this->actingAs($patient)->post(route('patient.documents.store'), [
            'document' => UploadedFile::fake()->createWithContent('b.pdf', self::PDF),
            'category' => 'prescription',
            'appointment_id' => $victimAppointment->id,
        ])->assertSessionHasErrors('appointment_id');

        $this->assertSame(1, MedicalDocument::count());
        $this->assertSame(0, $victim->medicalDocuments()->count());
    }

    public function test_client_filenames_with_paths_are_reduced_to_a_safe_label(): void
    {
        $patient = $this->patient();
        $file = UploadedFile::fake()->createWithContent("..\\..\\windows/evil\x07name.pdf", self::PDF);

        $this->actingAs($patient)->post(route('patient.documents.store'), ['document' => $file, 'category' => 'other'])->assertSessionHasNoErrors();

        $this->assertSame('evilname.pdf', MedicalDocument::firstOrFail()->original_name);
    }

    public function test_dangerous_wrong_and_oversized_files_are_rejected_without_storing_anything(): void
    {
        $patient = $this->patient();
        $rejected = [
            'svg' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'html' => UploadedFile::fake()->createWithContent('x.html', '<html><script>alert(1)</script></html>'),
            'html renamed to pdf' => UploadedFile::fake()->createWithContent('x.pdf', '<html><script>alert(1)</script></html>'),
            'php renamed to png' => UploadedFile::fake()->createWithContent('shell.png', '<?php system($_GET["c"]); ?>'),
            'executable' => UploadedFile::fake()->createWithContent('run.exe', "MZ\x90\x00\x03".str_repeat("\0", 64)),
            'pdf with exe extension' => UploadedFile::fake()->createWithContent('doc.exe', self::PDF),
            'word document' => UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'gif' => UploadedFile::fake()->createWithContent('a.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00;"),
            'oversized pdf' => UploadedFile::fake()->createWithContent('big.pdf', self::PDF.str_repeat('0', 10 * 1024 * 1024 + 1)),
            'missing file' => null,
        ];

        foreach ($rejected as $label => $file) {
            $this->actingAs($patient)
                ->post(route('patient.documents.store'), array_filter(['document' => $file, 'category' => 'other']))
                ->assertSessionHasErrors('document');
        }

        $this->assertSame(0, MedicalDocument::count(), 'No rejected file is recorded.');
        $this->assertSame([], Storage::disk(MedicalDocumentService::DISK)->allFiles(), 'No rejected file is stored.');
    }

    public function test_an_image_discarded_by_the_php_upload_limit_gets_an_accurate_message(): void
    {
        $patient = $this->patient();
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $this->pngBytes());
        $discardedByPhp = new UploadedFile($path, 'phone-photo.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->actingAs($patient)
            ->post(route('patient.documents.store'), ['document' => $discardedByPhp, 'category' => 'imaging_report'])
            ->assertSessionHasErrors(['document' => 'The file could not be uploaded. Files must be PDF, JPG, PNG or WebP and no larger than '.MedicalDocument::maxUploadLabel().'.']);

        $this->assertSame(0, MedicalDocument::count());
        $this->assertSame([], Storage::disk(MedicalDocumentService::DISK)->allFiles());
        @unlink($path);
    }

    public function test_upload_forms_advertise_the_effective_upload_limit(): void
    {
        $expectedBytes = (int) min(MedicalDocument::MAX_KILOBYTES * 1024, UploadedFile::getMaxFilesize());
        $this->assertSame($expectedBytes, MedicalDocument::maxUploadBytes());
        $this->assertLessThanOrEqual(10 * 1024 * 1024, MedicalDocument::maxUploadBytes());

        $patient = $this->patient();
        foreach (['patient.documents.index', 'patient.dashboard'] as $page) {
            $this->actingAs($patient)->get(route($page))->assertOk()
                ->assertSee('data-max-file-bytes="'.$expectedBytes.'"', false)
                ->assertSee('up to '.MedicalDocument::maxUploadLabel().'</span>', false);
        }
    }

    public function test_categories_and_text_fields_are_validated(): void
    {
        $patient = $this->patient();

        foreach ([['category' => 'xray'], ['category' => ''], ['category' => 'Prescription'], ['title' => str_repeat('a', 151)], ['description' => str_repeat('a', 2001)], ['appointment_id' => 'abc']] as $override) {
            $this->actingAs($patient)->post(route('patient.documents.store'), [
                'document' => UploadedFile::fake()->createWithContent('a.pdf', self::PDF),
                'category' => 'prescription',
                ...$override,
            ])->assertSessionHasErrors(array_key_first($override));
        }

        $this->assertSame(0, MedicalDocument::count());
    }

    public function test_a_failed_database_write_leaves_no_orphaned_file(): void
    {
        $patient = $this->patient();
        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'insert into "medical_document_events"')) {
                throw new RuntimeException('Simulated database failure.');
            }
        });

        try {
            app(MedicalDocumentService::class)->store($patient, UploadedFile::fake()->createWithContent('a.pdf', self::PDF), ['category' => 'other']);
            $this->fail('The simulated failure should propagate.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, MedicalDocument::count());
        $this->assertSame([], Storage::disk(MedicalDocumentService::DISK)->allFiles());
    }

    public function test_missing_file_is_handled_gracefully(): void
    {
        $patient = $this->patient();
        $document = $this->document($patient);
        Storage::disk(MedicalDocumentService::DISK)->delete($document->storage_path);

        $this->actingAs($patient)->from(route('patient.documents.index'))
            ->get(route('medical-documents.download', $document))
            ->assertRedirect(route('patient.documents.index'))
            ->assertSessionHasErrors(['document' => 'This document is currently unavailable. Please contact support.']);

        $this->actingAs($patient)->delete(route('medical-documents.destroy', $document))->assertRedirect();
        $this->assertModelMissing($document);
    }

    public function test_documents_survive_appointment_status_changes_and_appointment_deletion(): void
    {
        $patient = $this->patient();
        $appointment = $this->appointment($patient);
        $document = $this->document($patient, ['appointment_id' => $appointment->id]);

        foreach (['cancelled', 'rejected', 'completed'] as $status) {
            $appointment->update(['status' => $status]);
            $this->actingAs($patient)->get(route('medical-documents.download', $document))->assertOk();
        }

        $appointment->delete();

        $this->assertModelExists($document);
        $this->assertNull($document->fresh()->appointment_id);
        Storage::disk(MedicalDocumentService::DISK)->assertExists($document->storage_path);
        $this->actingAs($patient)->get(route('patient.documents.index'))->assertOk()->assertSee($document->displayTitle());
    }

    public function test_dashboard_offers_an_upload_form_and_the_records_page_lists_own_appointments(): void
    {
        $patient = $this->patient();
        $own = $this->appointment($patient);
        $othersAppointment = $this->appointment($this->patient());

        $this->actingAs($patient)->get(route('patient.dashboard'))->assertOk()
            ->assertSee(route('patient.documents.store'), false)
            ->assertSee('enctype="multipart/form-data"', false);

        $page = $this->actingAs($patient)->get(route('patient.documents.index'))->assertOk()->assertSee('No documents yet');
        $page->assertSee('value="'.$own->id.'"', false)->assertDontSee('value="'.$othersAppointment->id.'"', false);
    }

    public function test_guests_cannot_list_upload_download_or_delete(): void
    {
        $document = $this->document($this->patient());

        $this->get(route('patient.documents.index'))->assertRedirect(route('login'));
        $this->post(route('patient.documents.store'), ['document' => UploadedFile::fake()->createWithContent('a.pdf', self::PDF), 'category' => 'other'])->assertRedirect(route('login'));
        $this->get(route('medical-documents.download', $document))->assertRedirect(route('login'));
        $this->delete(route('medical-documents.destroy', $document))->assertRedirect(route('login'));
        $this->get(route('doctor.medical-records.index'))->assertRedirect(route('login'));
        $this->get(route('admin.medical-documents.index'))->assertRedirect(route('login'));

        $this->assertSame(1, MedicalDocument::count());
        Storage::disk(MedicalDocumentService::DISK)->assertExists($document->storage_path);
    }

    public function test_deactivated_patient_loses_access(): void
    {
        $patient = $this->patient();
        $document = $this->document($patient);
        $patient->forceFill(['deactivated_at' => now()])->save();
        $patient = $patient->fresh();

        $this->actingAs($patient)->get(route('patient.documents.index'))->assertForbidden();
        $this->actingAs($patient)->get(route('medical-documents.download', $document))->assertForbidden();
        $this->actingAs($patient)->delete(route('medical-documents.destroy', $document))->assertForbidden();
    }

    private function document(User $patient, array $details = []): MedicalDocument
    {
        $document = app(MedicalDocumentService::class)->store(
            $patient,
            UploadedFile::fake()->createWithContent('report.pdf', self::PDF),
            ['category' => 'laboratory_report', ...$details]
        );

        return $document->fresh();
    }

    private function appointment(User $patient): Appointment
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $profile = DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);
        $slot = AppointmentSlot::create(['doctor_profile_id' => $profile->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addMinutes(10), 'status' => 'booked']);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => 'booked', 'consultation_type' => 'physical']);
    }

    private function patient(?string $name = null): User
    {
        return User::factory()->create(['role' => 'patient', ...($name ? ['name' => $name] : [])]);
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
}
