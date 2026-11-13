<?php

namespace App\Policies;

use App\Models\MedicalDocument;
use App\Models\User;
use App\Services\DoctorAccess;

/**
 * Medical documents belong to the patient.
 *  - Patients manage (list, upload, download, delete) only their own documents.
 *  - Doctors, and Doctor Assistants currently assigned to an active doctor, may list, preview and download
 *    documents of patients in that doctor's care: at least one booked, confirmed or completed appointment with
 *    the doctor, checked against the patient's appointment history (never the document's optional appointment
 *    link). Neither can upload or delete. Each check is decided by DoctorAccess with its own ability.
 *  - Admins may list, inspect, download and delete every document.
 *  - Deactivated accounts and guests have no access.
 *
 * Downloads are audited by MedicalDocumentService against the signed-in user, so an assistant's downloads are
 * recorded under the assistant's own user id.
 */
class MedicalDocumentPolicy
{
    public function __construct(private readonly DoctorAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->isActive($user) && in_array($user->role, ['patient', 'doctor', 'admin', User::ROLE_ASSISTANT], true);
    }

    public function create(User $user): bool
    {
        return $this->isActive($user) && $user->role === 'patient';
    }

    public function view(User $user, MedicalDocument $medicalDocument): bool
    {
        return $this->allows($user, $medicalDocument, DoctorAccess::VIEW_MEDICAL_DOCUMENTS);
    }

    public function download(User $user, MedicalDocument $medicalDocument): bool
    {
        return $this->allows($user, $medicalDocument, DoctorAccess::DOWNLOAD_MEDICAL_DOCUMENTS);
    }

    public function preview(User $user, MedicalDocument $medicalDocument): bool
    {
        return $medicalDocument->isPreviewableImage()
            && $this->allows($user, $medicalDocument, DoctorAccess::PREVIEW_MEDICAL_DOCUMENTS);
    }

    public function delete(User $user, MedicalDocument $medicalDocument): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        return $user->role === 'admin'
            || ($user->role === 'patient' && $medicalDocument->patient_id === $user->id);
    }

    private function allows(User $user, MedicalDocument $medicalDocument, string $ability): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        return match ($user->role) {
            'admin' => true,
            'patient' => $medicalDocument->patient_id === $user->id,
            'doctor', User::ROLE_ASSISTANT => $this->access->hasCareRelationship($user, $medicalDocument->patient_id, $ability),
            default => false,
        };
    }

    private function isActive(User $user): bool
    {
        return $user->deactivated_at === null;
    }
}
