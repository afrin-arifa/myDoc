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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MedicalDocumentDoctorAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private int $slotOffset = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MedicalDocumentService::DISK);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function relationshipStatuses(): iterable
    {
        yield 'booked appointment grants access' => ['booked', true];
        yield 'confirmed appointment grants access' => ['confirmed', true];
        yield 'completed appointment grants access' => ['completed', true];
        yield 'cancelled only does not' => ['cancelled', false];
        yield 'rejected only does not' => ['rejected', false];
    }

    #[DataProvider('relationshipStatuses')]
    public function test_doctor_access_follows_the_care_relationship(string $status, bool $allowed): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient('Care Patient');
        $this->appointment($doctor, $patient, $status);
        $document = $this->document($patient, ['title' => 'Care document']);

        $list = $this->actingAs($doctor)->get(route('doctor.medical-records.index'))->assertOk();
        $download = $this->actingAs($doctor)->get(route('medical-documents.download', $document));

        if ($allowed) {
            $list->assertSee('Care document')->assertSee('Care Patient');
            $download->assertOk();
            $this->assertSame(self::PDF, $download->streamedContent());
            $this->assertSame(1, MedicalDocumentEvent::where('action', 'downloaded')->where('actor_id', $doctor->id)->count());
        } else {
            $list->assertDontSee('Care document')->assertSee('No medical records found');
            $download->assertForbidden();
            $this->assertStringNotContainsString(self::PDF, (string) $download->getContent());
        }
    }

    public function test_doctor_sees_only_documents_of_their_own_patients(): void
    {
        $doctorA = $this->doctor();
        $doctorB = $this->doctor();
        $mine = $this->patient('Mine Patient');
        $theirs = $this->patient('Theirs Patient');
        $stranger = $this->patient('Stranger Patient');
        $this->appointment($doctorA, $mine, 'confirmed');
        $this->appointment($doctorB, $theirs, 'confirmed');
        $mineDocument = $this->document($mine, ['title' => 'Mine lab']);
        $theirsDocument = $this->document($theirs, ['title' => 'Theirs lab']);
        $strangerDocument = $this->document($stranger, ['title' => 'Stranger lab']);

        $response = $this->actingAs($doctorA)->get(route('doctor.medical-records.index'))->assertOk();
        $this->assertSame([$mineDocument->id], $response->viewData('documents')->pluck('id')->all());
        $response->assertDontSee('Theirs lab')->assertDontSee('Stranger lab')->assertDontSee($mineDocument->storage_path);

        foreach ([$theirsDocument, $strangerDocument] as $document) {
            $this->actingAs($doctorA)->get(route('medical-documents.download', $document))->assertForbidden();
        }
        $this->actingAs($doctorB)->get(route('medical-documents.download', $mineDocument))->assertForbidden();
        $this->actingAs($doctorB)->get(route('medical-documents.download', $theirsDocument))->assertOk();
    }

    public function test_query_parameters_and_ids_cannot_widen_the_doctors_scope(): void
    {
        $doctorA = $this->doctor();
        $doctorB = $this->doctor();
        $theirs = $this->patient('Theirs Patient');
        $this->appointment($doctorB, $theirs, 'confirmed');
        $theirsDocument = $this->document($theirs, ['title' => 'Theirs lab']);

        $attempts = [
            ['patient' => $theirs->id],
            ['search' => 'Theirs'],
            ['search' => 'Theirs', 'category' => 'laboratory_report', 'patient' => $theirs->id],
            ['doctor_profile_id' => $doctorB->doctorProfile->id],
            ['doctor' => $doctorB->id, 'page' => 1],
        ];
        foreach ($attempts as $query) {
            $response = $this->actingAs($doctorA)->get(route('doctor.medical-records.index', $query))->assertOk();
            $this->assertCount(0, $response->viewData('documents'));
            $response->assertDontSee('Theirs lab');
        }

        $this->actingAs($doctorA)->get(route('medical-documents.download', ['document' => $theirsDocument->id, 'patient' => $theirs->id]))->assertForbidden();
        $this->actingAs($doctorA)->get('/medical-documents/'.($theirsDocument->id + 999).'/download')->assertNotFound();
        $this->actingAs($doctorA)->get(route('admin.medical-documents.show', $theirsDocument))->assertForbidden();
    }

    public function test_document_link_to_another_doctors_appointment_neither_grants_nor_reveals_it(): void
    {
        $doctorA = $this->doctor();
        $doctorB = $this->doctor();
        $patient = $this->patient('Shared Patient');
        $this->appointment($doctorA, $patient, 'confirmed');
        $appointmentWithB = $this->appointment($doctorB, $patient, 'confirmed', 9);
        $linkedToB = $this->document($patient, ['title' => 'Linked to B', 'appointment_id' => $appointmentWithB->id]);

        $response = $this->actingAs($doctorA)->get(route('doctor.medical-records.index'))->assertOk()->assertSee('Linked to B');
        $this->assertNull($response->viewData('documents')->first()->appointment, "Doctor B's appointment is hidden from doctor A.");
        $response->assertDontSee(route('doctor.appointments.show', $appointmentWithB), false);
        $response->assertDontSee($appointmentWithB->slot->starts_at->format('M j, Y g:i A'));

        $this->actingAs($doctorB)->get(route('doctor.medical-records.index'))->assertOk()
            ->assertSee(route('doctor.appointments.show', $appointmentWithB), false);

        // An appointment link alone never grants access to a doctor with no care relationship.
        $doctorC = $this->doctor();
        $stranger = $this->patient();
        $cancelledWithC = $this->appointment($doctorC, $stranger, 'cancelled');
        $linkedToC = $this->document($stranger, ['appointment_id' => $cancelledWithC->id]);
        $this->actingAs($doctorC)->get(route('medical-documents.download', $linkedToC))->assertForbidden();
    }

    public function test_doctor_can_search_by_patient_or_title_and_filter_by_category(): void
    {
        $doctor = $this->doctor();
        $alice = $this->patient('Alice Rahman');
        $bob = $this->patient('Bob Karim');
        $this->appointment($doctor, $alice, 'confirmed');
        $this->appointment($doctor, $bob, 'completed');
        $this->document($alice, ['title' => 'CBC panel', 'category' => 'laboratory_report']);
        $this->document($alice, ['title' => 'Chest X-ray', 'category' => 'imaging_report']);
        $this->document($bob, ['title' => 'Amoxicillin', 'category' => 'prescription']);

        $titles = fn (array $query) => $this->actingAs($doctor)->get(route('doctor.medical-records.index', $query))->assertOk()
            ->viewData('documents')->pluck('title')->sort()->values()->all();

        $this->assertSame(['CBC panel', 'Chest X-ray'], $titles(['search' => 'Alice']));
        $this->assertSame(['Chest X-ray'], $titles(['search' => 'X-ray']));
        $this->assertSame(['Amoxicillin'], $titles(['category' => 'prescription']));
        $this->assertSame(['CBC panel'], $titles(['search' => 'Alice', 'category' => 'laboratory_report']));
        $this->assertSame(['CBC panel', 'Chest X-ray'], $titles(['patient' => $alice->id]));
        $this->actingAs($doctor)->get(route('doctor.medical-records.index', ['category' => 'bogus']))->assertSessionHasErrors('category');
    }

    public function test_doctor_list_is_paginated_without_n_plus_one_queries(): void
    {
        $doctor = $this->doctor();
        foreach (range(1, 18) as $index) {
            $patient = $this->patient("Paged Patient {$index}");
            $this->appointment($doctor, $patient, 'confirmed');
            $this->document($patient, ['title' => "Doc {$index}", 'appointment_id' => $patient->appointments()->value('id')]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $first = $this->actingAs($doctor)->get(route('doctor.medical-records.index'))->assertOk();

        $this->assertCount(15, $first->viewData('documents'));
        $this->assertSame(18, $first->viewData('documents')->total());
        $this->assertLessThan(20, $queries, 'Patients and appointments are eager loaded.');
        $this->assertCount(3, $this->actingAs($doctor)->get(route('doctor.medical-records.index', ['page' => 2]))->viewData('documents'));
    }

    public function test_doctor_cannot_upload_delete_or_reach_patient_and_admin_pages(): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient();
        $this->appointment($doctor, $patient, 'confirmed');
        $document = $this->document($patient);

        $this->actingAs($doctor)->delete(route('medical-documents.destroy', $document))->assertForbidden();
        $this->assertModelExists($document);
        Storage::disk(MedicalDocumentService::DISK)->assertExists($document->storage_path);

        $this->actingAs($doctor)->post(route('patient.documents.store'), [
            'document' => UploadedFile::fake()->createWithContent('a.pdf', self::PDF),
            'category' => 'prescription',
            'patient_id' => $patient->id,
        ])->assertForbidden();
        $this->actingAs($doctor)->get(route('patient.documents.index'))->assertForbidden();
        $this->actingAs($doctor)->get(route('admin.medical-documents.index'))->assertForbidden();
        $this->assertSame(1, MedicalDocument::count());
        $this->assertFalse($doctor->can('create', MedicalDocument::class));
    }

    public function test_deactivated_doctor_loses_access_and_sidebar_links_to_records(): void
    {
        $doctor = $this->doctor();
        $patient = $this->patient();
        $this->appointment($doctor, $patient, 'confirmed');
        $document = $this->document($patient);

        $this->actingAs($doctor)->get(route('doctor.dashboard'))->assertOk()->assertSee(route('doctor.medical-records.index'), false);

        $doctor->forceFill(['deactivated_at' => now()])->save();
        $doctor = $doctor->fresh();
        $this->actingAs($doctor)->get(route('doctor.medical-records.index'))->assertForbidden();
        $this->actingAs($doctor)->get(route('medical-documents.download', $document))->assertForbidden();
    }

    public function test_existing_doctor_isolation_and_patient_list_are_unchanged(): void
    {
        $doctorA = $this->doctor();
        $doctorB = $this->doctor();
        $patient = $this->patient('Isolation Patient');
        $appointmentB = $this->appointment($doctorB, $patient, 'confirmed');
        $this->document($patient);

        $this->actingAs($doctorA)->get(route('doctor.appointments.show', $appointmentB))->assertForbidden();
        $this->actingAs($doctorA)->get(route('doctor.patients.index'))->assertOk()->assertDontSee('Isolation Patient');
        $this->actingAs($doctorB)->get(route('doctor.patients.index'))->assertOk()->assertSee('Isolation Patient');
    }

    private function document(User $patient, array $details = []): MedicalDocument
    {
        return app(MedicalDocumentService::class)->store(
            $patient,
            UploadedFile::fake()->createWithContent('report.pdf', self::PDF),
            ['category' => 'laboratory_report', ...$details]
        )->fresh();
    }

    private function appointment(User $doctor, User $patient, string $status, int $daysAhead = 3): Appointment
    {
        $startsAt = now()->startOfHour()->addDays($daysAhead)->addMinutes(10 * $this->slotOffset++);
        $slot = AppointmentSlot::create([
            'doctor_profile_id' => $doctor->doctorProfile->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(10),
            'status' => in_array($status, ['cancelled', 'rejected'], true) ? 'available' : 'booked',
        ]);

        return Appointment::create(['appointment_slot_id' => $slot->id, 'patient_id' => $patient->id, 'status' => $status, 'consultation_type' => 'physical']);
    }

    private function patient(?string $name = null): User
    {
        return User::factory()->create(['role' => 'patient', ...($name ? ['name' => $name] : [])]);
    }

    private function doctor(): User
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        DoctorProfile::create(['user_id' => $doctor->id, 'professional_title' => 'GP', 'license_number' => 'LIC-'.$doctor->id, 'consultation_fee' => 10]);

        return $doctor->load('doctorProfile');
    }
}
