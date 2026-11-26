<?php

namespace Tests\Feature;

use App\Models\MedicalDocument;
use App\Models\User;
use App\Services\MedicalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every page refuses to be framed by other sites and stops content-type guessing; file responses keep their own
 * stricter values.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_send_baseline_security_headers(): void
    {
        $patient = User::factory()->create(['role' => 'patient']);

        foreach ([$this->get(route('login')), $this->actingAs($patient)->get(route('patient.dashboard'))] as $response) {
            $response->assertOk()
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
    }

    public function test_a_response_that_sets_its_own_header_keeps_it(): void
    {
        Storage::fake(MedicalDocumentService::DISK);
        $patient = User::factory()->create(['role' => 'patient']);
        Storage::disk(MedicalDocumentService::DISK)->put('medical-documents/report.pdf', '%PDF-1.4 test');
        $document = MedicalDocument::query()->forceCreate([
            'patient_id' => $patient->id, 'uploaded_by' => $patient->id, 'category' => array_key_first(MedicalDocument::CATEGORIES),
            'title' => 'Report', 'original_name' => 'report.pdf', 'storage_path' => 'medical-documents/report.pdf', 'mime_type' => 'application/pdf', 'size' => 13,
        ]);

        $this->actingAs($patient)->get(route('medical-documents.download', $document))->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY');
    }
}
