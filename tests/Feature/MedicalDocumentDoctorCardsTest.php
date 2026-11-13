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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MedicalDocumentDoctorCardsTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private User $doctor;

    private User $patient;

    private Appointment $appointment;

    private int $slotOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MedicalDocumentService::DISK);
        $this->doctor = $this->doctor('Dr. Card Viewer');
        $this->patient = User::factory()->create(['role' => 'patient', 'name' => 'Rina Akter', 'email' => 'rina.private@example.com']);
        $this->appointment = $this->appointment($this->doctor, $this->patient, 'confirmed');
    }

    public function test_doctor_sees_patient_documents_as_cards_with_previews_and_details(): void
    {
        $scan = $this->document($this->patient, 'chest-scan.png', $this->pngBytes(), ['title' => 'Chest X-ray', 'category' => 'imaging_report', 'appointment_id' => $this->appointment->id]);
        $pdf = $this->document($this->patient, 'blood-test.pdf', self::PDF, ['title' => null, 'category' => 'laboratory_report']);

        $page = $this->actingAs($this->doctor)->get(route('doctor.medical-records.index'))->assertOk();

        $page->assertSee('row row-cols-1 row-cols-md-2 row-cols-xxl-3 g-4', false)
            ->assertSee('class="card document-card h-100"', false)
            ->assertSee('src="'.route('medical-documents.preview', $scan).'"', false)
            ->assertSee('data-bs-target="#document-preview"', false)
            ->assertSee('id="document-preview"', false)
            ->assertDontSee(route('medical-documents.preview', $pdf), false)
            ->assertSee('fa-file-pdf', false)->assertSee('PDF document')
            ->assertSee('<span class="document-card__type">PNG</span>', false)
            ->assertSee('<span class="document-card__type">PDF</span>', false)
            ->assertSee('Imaging report')->assertSee('Laboratory report')
            ->assertSee('Chest X-ray')->assertSee('chest-scan.png')->assertSee('blood-test.pdf')
            ->assertSee($scan->created_at->format('M j, Y'))
            ->assertSee('PNG · '.$scan->humanSize())->assertSee('PDF · '.$pdf->humanSize())
            ->assertSee('Rina Akter')
            ->assertSee(route('doctor.appointments.show', $this->appointment), false)
            ->assertSee($this->appointment->slot->starts_at->format('M j, Y g:i A'))
            ->assertSee('Not linked')
            ->assertSee(route('medical-documents.download', $scan), false)
            ->assertSee(route('medical-documents.download', $pdf), false)
            ->assertSee('2 documents');
    }

    public function test_doctor_cards_offer_no_delete_and_expose_no_private_details(): void
    {
        $document = $this->document($this->patient, 'report.png', $this->pngBytes(), ['description' => 'Private symptom notes']);

        $html = $this->actingAs($this->doctor)->get(route('doctor.medical-records.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('action="'.route('medical-documents.destroy', $document).'"', $html);
        $this->assertStringNotContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringNotContainsString('btn-outline-danger', $html);
        $this->assertStringNotContainsString('>Delete<', $html);
        $this->assertStringNotContainsString($document->storage_path, $html);
        $this->assertStringNotContainsString('/storage/', $html);
        $this->assertStringNotContainsString('Private symptom notes', $html);
        $this->assertStringNotContainsString('rina.private@example.com', $html);

        $this->actingAs($this->doctor)->delete(route('medical-documents.destroy', $document))->assertForbidden();
        $this->assertModelExists($document);
        Storage::disk(MedicalDocumentService::DISK)->assertExists($document->storage_path);
    }

    public function test_previews_and_downloads_follow_the_care_relationship(): void
    {
        $inCare = $this->document($this->patient, 'mine.png', $this->pngBytes());
        $inCarePdf = $this->document($this->patient, 'mine.pdf', self::PDF);
        $stranger = User::factory()->create(['role' => 'patient', 'name' => 'Stranger Patient']);
        $this->appointment($this->doctor('Dr. Other'), $stranger, 'confirmed');
        $notInCare = $this->document($stranger, 'theirs.png', $this->pngBytes(), ['title' => 'Stranger scan']);

        $this->actingAs($this->doctor)->get(route('medical-documents.preview', $inCare))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->doctor)->get(route('medical-documents.download', $inCarePdf))->assertOk();
        $this->actingAs($this->doctor)->get(route('medical-documents.preview', $inCarePdf))->assertForbidden();
        $this->actingAs($this->doctor)->get(route('medical-documents.preview', $notInCare))->assertForbidden();
        $this->actingAs($this->doctor)->get(route('medical-documents.download', $notInCare))->assertForbidden();

        $this->actingAs($this->doctor)->get(route('doctor.medical-records.index'))->assertOk()
            ->assertDontSee('Stranger scan')->assertDontSee('Stranger Patient')
            ->assertDontSee(route('medical-documents.preview', $notInCare), false);
    }

    public function test_cancelled_only_patients_never_appear_as_cards(): void
    {
        $formerPatient = User::factory()->create(['role' => 'patient', 'name' => 'Cancelled Patient']);
        $this->appointment($this->doctor, $formerPatient, 'cancelled');
        $document = $this->document($formerPatient, 'old.png', $this->pngBytes(), ['title' => 'Cancelled scan']);

        $this->actingAs($this->doctor)->get(route('doctor.medical-records.index'))->assertOk()
            ->assertDontSee('Cancelled scan')->assertDontSee(route('medical-documents.preview', $document), false)
            ->assertSee('No medical records found');
    }

    public function test_search_and_type_filter_still_drive_the_card_grid(): void
    {
        $other = User::factory()->create(['role' => 'patient', 'name' => 'Karim Hasan']);
        $this->appointment($this->doctor, $other, 'completed');
        $this->document($this->patient, 'rx.pdf', self::PDF, ['title' => 'Antibiotic prescription', 'category' => 'prescription']);
        $this->document($other, 'ct.png', $this->pngBytes(), ['title' => 'CT head', 'category' => 'imaging_report']);

        $titles = fn (array $query) => $this->actingAs($this->doctor)->get(route('doctor.medical-records.index', $query))->assertOk()
            ->viewData('documents')->pluck('title')->sort()->values()->all();

        $this->assertSame(['Antibiotic prescription', 'CT head'], $titles([]));
        $this->assertSame(['Antibiotic prescription'], $titles(['search' => 'Antibiotic']));
        $this->assertSame(['CT head'], $titles(['search' => 'Karim']));
        $this->assertSame(['CT head'], $titles(['category' => 'imaging_report']));
        $this->assertSame([], $titles(['search' => 'Karim', 'category' => 'prescription']));

        $this->actingAs($this->doctor)->get(route('doctor.medical-records.index', ['category' => 'medical_certificate']))->assertOk()
            ->assertSee('No documents match these filters.')->assertSee('Show all records')
            ->assertSee('value="medical_certificate" selected', false);
    }

    public function test_doctor_card_pages_keep_pagination(): void
    {
        foreach (range(1, 16) as $number) {
            $this->document($this->patient, "lab-{$number}.pdf", self::PDF, ['title' => "Lab {$number}"]);
        }

        $this->actingAs($this->doctor)->get(route('doctor.medical-records.index'))->assertOk()
            ->assertViewHas('documents', fn ($documents) => $documents->count() === 15 && $documents->total() === 16)
            ->assertSee('16 documents')->assertSee('page=2', false);
        $this->actingAs($this->doctor)->get(route('doctor.medical-records.index', ['page' => 2]))->assertOk()
            ->assertViewHas('documents', fn ($documents) => $documents->count() === 1);
    }

    public function test_patient_cards_still_offer_delete_and_the_linked_doctor(): void
    {
        $document = $this->document($this->patient, 'own.png', $this->pngBytes(), ['appointment_id' => $this->appointment->id]);

        $this->actingAs($this->patient)->get(route('patient.documents.index'))->assertOk()
            ->assertSee('action="'.route('medical-documents.destroy', $document).'"', false)
            ->assertSee('Dr. Card Viewer')
            ->assertDontSee(route('doctor.appointments.show', $this->appointment), false)
            ->assertSee('id="document-preview"', false);
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
        $startsAt = now()->startOfHour()->addDays(2)->addMinutes(10 * $this->slotOffset++);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(10),
            'status' => $status === 'cancelled' ? 'available' : 'booked',
        ]);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => $status, 'consultation_type' => 'physical']);
    }

    private function doctor(string $name): User
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => $name]);
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
}
