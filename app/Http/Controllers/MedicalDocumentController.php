<?php

namespace App\Http\Controllers;

use App\Http\Requests\MedicalDocument\StoreMedicalDocumentRequest;
use App\Models\Appointment;
use App\Models\MedicalDocument;
use App\Services\DoctorWorkspace;
use App\Services\MedicalDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MedicalDocumentController extends Controller
{
    public function __construct(private readonly MedicalDocumentService $documents) {}

    /**
     * The signed-in patient's own documents.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', MedicalDocument::class);

        $category = $request->validate([
            'category' => ['nullable', 'in:'.implode(',', array_keys(MedicalDocument::CATEGORIES))],
        ])['category'] ?? null;

        $documents = $request->user()->medicalDocuments()
            ->with(['appointment.slot' => fn ($slots) => $slots->without('location'), 'appointment.slot.doctorProfile.user:id,name'])
            ->when($category, fn ($query) => $query->where('category', $category))
            ->latest()
            ->latest('id')
            ->paginate(12)
            ->withQueryString();
        MedicalDocument::loadStaffUploaders($documents->getCollection());

        return view('patient.documents.index', [
            'documents' => $documents,
            'appointments' => $this->appointmentOptions($request),
            'category' => $category,
        ]);
    }

    public function store(StoreMedicalDocumentRequest $request): RedirectResponse
    {
        $this->documents->store($request->user(), $request->file('document'), $request->details(), $request->ip());

        return redirect()->route('patient.documents.index')->with('status', 'Your document has been uploaded.');
    }

    /**
     * Authorized download for patients, doctors in the patient's care and admins on the shared route, and for
     * assistants only through a doctor's workspace route (see MedicalDocumentPolicy).
     */
    public function download(Request $request, MedicalDocument $document, DoctorWorkspace $workspace): StreamedResponse|RedirectResponse
    {
        Gate::authorize('download', [$document, $workspace->currentDoctorProfile()]);

        return $this->documents->download($document, $request->user(), $request->ip())
            ?? back()->withErrors(['document' => 'This document is currently unavailable. Please contact support.']);
    }

    /**
     * Inline image preview (thumbnails and the preview modal). PDFs and anything that is not a validated image
     * are refused; they are only available through the attachment download.
     */
    public function preview(MedicalDocument $document, DoctorWorkspace $workspace): StreamedResponse
    {
        Gate::authorize('preview', [$document, $workspace->currentDoctorProfile()]);

        return $this->documents->preview($document) ?? abort(404);
    }

    public function destroy(Request $request, MedicalDocument $document): RedirectResponse
    {
        Gate::authorize('delete', $document);

        $this->documents->delete($document, $request->user(), $request->ip());

        return redirect()
            ->route($request->user()->role === 'admin' ? 'admin.medical-documents.index' : 'patient.documents.index')
            ->with('status', 'The document has been deleted.');
    }

    /**
     * The patient's own appointments, offered as optional links for an upload.
     *
     * @return Collection<int, Appointment>
     */
    private function appointmentOptions(Request $request)
    {
        return $request->user()->appointments()
            ->with('slot.doctorProfile.user:id,name')
            ->join('appointment_slots', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
            ->orderByDesc('appointment_slots.starts_at')
            ->select('appointments.*')
            ->limit(50)
            ->get();
    }
}
