<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\MedicalDocument\StoreWorkspaceMedicalDocumentRequest;
use App\Models\Appointment;
use App\Models\DoctorWorkspaceEvent;
use App\Models\MedicalDocument;
use App\Models\User;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use App\Services\DoctorWorkspaceEventService;
use App\Services\MedicalDocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DoctorMedicalRecordController extends Controller
{
    /**
     * Documents of patients in this doctor's care. Searching, filtering or passing a patient id can only narrow
     * this scope, never widen it.
     */
    public function index(Request $request, DoctorWorkspace $workspace, DoctorAccess $access): View
    {
        Gate::authorize('viewAny', MedicalDocument::class);
        $profile = $workspace->forRequest($request, DoctorAccess::VIEW_MEDICAL_DOCUMENTS)?->load(['user', 'specialties']);
        abort_unless($profile, 403);
        $profileIds = [$profile->id];

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'in:'.implode(',', array_keys(MedicalDocument::CATEGORIES))],
            'patient' => ['nullable', 'string', 'max:20'],
        ]);
        $search = $filters['search'] ?? null;

        $documents = MedicalDocument::query()
            ->visibleToDoctorProfiles($profileIds)
            ->with(['patient:id,name,email,phone', 'appointment.slot' => fn ($slots) => $slots->without('location')])
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['patient'] ?? null, fn (Builder $query, string $patientKey) => $query->where('patient_id', User::idFromUrlKey($patientKey) ?? 0))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $matches) => $matches
                ->where('title', 'like', "%{$search}%")
                ->orWhereHas('patient', fn (Builder $patients) => $patients->where('name', 'like', "%{$search}%"))))
            ->latest()
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        MedicalDocument::loadStaffUploaders($documents->getCollection());

        // A document may be linked to another doctor's appointment; that link is the other doctor's data.
        $documents->getCollection()->each(fn (MedicalDocument $document) => $document->setRelation(
            'appointment',
            in_array((int) $document->appointment?->slot?->doctor_profile_id, $profileIds, true) ? $document->appointment : null
        ));

        // Within this workspace the actor already acts for the doctor, so only the role's ability list decides.
        $canUpload = $workspace->routeAvailable('doctor.medical-records.store')
            && $workspace->inAssistantWorkspace()
            && $access->allows($request->user(), DoctorAccess::UPLOAD_MEDICAL_DOCUMENTS);

        return view('doctor.medical-records.index', [
            'profile' => $profile,
            'documents' => $documents,
            'filters' => $filters,
            'canUpload' => $canUpload,
        ]);
    }

    /**
     * An assistant uploads a document for a patient in the selected doctor's care. The upload is recorded in the
     * document's own audit trail and in the doctor's workspace activity, under the assistant's account.
     */
    public function store(
        StoreWorkspaceMedicalDocumentRequest $request,
        DoctorWorkspace $workspace,
        DoctorAccess $access,
        MedicalDocumentService $documents,
        DoctorWorkspaceEventService $events,
    ): RedirectResponse {
        $profile = $workspace->forRequest($request, DoctorAccess::UPLOAD_MEDICAL_DOCUMENTS);
        abort_unless($profile, 403);

        $patient = User::query()->where('role', 'patient')->find($request->patientId());

        if ($patient === null || ! $access->hasCareRelationship($request->user(), $patient->id, DoctorAccess::UPLOAD_MEDICAL_DOCUMENTS, $profile)) {
            throw ValidationException::withMessages(['patient' => 'Choose a patient who has an appointment with '.$profile->user->name.'.']);
        }

        $document = $documents->store(
            $patient,
            $request->file('document'),
            $request->details(),
            $request->ip(),
            $request->user(),
            fn (MedicalDocument $document) => $events->record($request->user(), $profile, DoctorWorkspaceEvent::ACTION_DOCUMENT_UPLOADED, context: [
                'medical_document_id' => $document->id,
                'document_category' => $document->category,
                'patient_name' => $patient->name,
            ]),
        );

        return redirect($workspace->url('doctor.medical-records.index'))
            ->with('status', $document->categoryLabel().' uploaded for '.$patient->name.'.');
    }

    /**
     * Patients an assistant may upload a document for (a booked, confirmed or completed appointment with this
     * doctor), matching the search, for the upload form's patient picker.
     */
    public function searchPatients(Request $request, DoctorWorkspace $workspace): JsonResponse
    {
        $profile = $workspace->forRequest($request, DoctorAccess::UPLOAD_MEDICAL_DOCUMENTS);
        abort_unless($profile, 403);

        $search = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']])['search'];

        $patients = User::query()
            ->activePatients()
            ->whereHas('appointments', fn (Builder $appointments) => $appointments
                ->whereIn('status', MedicalDocument::CARE_STATUSES)
                ->whereHas('slot', fn (Builder $slots) => $slots->where('doctor_profile_id', $profile->id)))
            ->where(fn (Builder $matches) => $matches
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%"))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email', 'phone']);

        return response()->json([
            'data' => $patients->map(fn (User $patient): array => [
                'key' => $patient->getRouteKey(),
                'name' => $patient->name,
                'email' => $patient->contactLabel(),
            ])->all(),
        ]);
    }
}
