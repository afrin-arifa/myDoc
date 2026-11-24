<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\MedicalDocument;
use App\Models\User;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DoctorMedicalRecordController extends Controller
{
    /**
     * Documents of patients in this doctor's care. Searching, filtering or passing a patient id can only narrow
     * this scope, never widen it.
     */
    public function index(Request $request, DoctorWorkspace $workspace): View
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
            ->with(['patient:id,name,email,phone', 'appointment.slot'])
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['patient'] ?? null, fn (Builder $query, string $patientKey) => $query->where('patient_id', User::idFromUrlKey($patientKey) ?? 0))
            ->when($search, fn (Builder $query) => $query->where(fn (Builder $matches) => $matches
                ->where('title', 'like', "%{$search}%")
                ->orWhereHas('patient', fn (Builder $patients) => $patients->where('name', 'like', "%{$search}%"))))
            ->latest()
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        // A document may be linked to another doctor's appointment; that link is the other doctor's data.
        $documents->getCollection()->each(fn (MedicalDocument $document) => $document->setRelation(
            'appointment',
            in_array((int) $document->appointment?->slot?->doctor_profile_id, $profileIds, true) ? $document->appointment : null
        ));

        return view('doctor.medical-records.index', [
            'profile' => $profile,
            'documents' => $documents,
            'filters' => $filters,
            'pendingCount' => Appointment::query()
                ->where('status', 'booked')
                ->whereHas('slot', fn (Builder $slots) => $slots->where('doctor_profile_id', $profile->id))
                ->count(),
        ]);
    }
}
