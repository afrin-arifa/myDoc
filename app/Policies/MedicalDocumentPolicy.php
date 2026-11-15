<?php

namespace App\Policies;

use App\Models\DoctorProfile;
use App\Models\MedicalDocument;
use App\Models\User;
use App\Services\DoctorAccess;

/**
 * Medical documents belong to the patient.
 *  - Patients manage (list, upload, download, delete) only their own documents.
 *  - Doctors may list, preview and download documents of patients in their care: at least one booked, confirmed
 *    or completed appointment with them, checked against the patient's appointment history (never the document's
 *    optional appointment link). Each check is decided by DoctorAccess with its own ability.
 *  - Doctor Assistants have the same access only inside one doctor's workspace: the caller passes the doctor
 *    selected by the workspace URL (already resolved and checked by the works-for-doctor middleware), the
 *    assistant must still be assigned to that doctor and the patient must be in that doctor's care. Without a
 *    doctor context (the shared /medical-documents routes) assistants are refused, so a document id can never
 *    reach patients of another assigned doctor. Neither doctors nor assistants can upload or delete.
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

    public function view(User $user, MedicalDocument $medicalDocument, ?DoctorProfile $doctorProfile = null): bool
    {
        return $this->allows($user, $medicalDocument, DoctorAccess::VIEW_MEDICAL_DOCUMENTS, $doctorProfile);
    }

    public function download(User $user, MedicalDocument $medicalDocument, ?DoctorProfile $doctorProfile = null): bool
    {
        return $this->allows($user, $medicalDocument, DoctorAccess::DOWNLOAD_MEDICAL_DOCUMENTS, $doctorProfile);
    }

    public function preview(User $user, MedicalDocument $medicalDocument, ?DoctorProfile $doctorProfile = null): bool
    {
        return $medicalDocument->isPreviewableImage()
            && $this->allows($user, $medicalDocument, DoctorAccess::PREVIEW_MEDICAL_DOCUMENTS, $doctorProfile);
    }

    public function delete(User $user, MedicalDocument $medicalDocument): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        return $user->role === 'admin'
            || ($user->role === 'patient' && $medicalDocument->patient_id === $user->id);
    }

    /**
     * The doctor context only narrows access: it is required for assistants and limits a doctor to that profile.
     */
    private function allows(User $user, MedicalDocument $medicalDocument, string $ability, ?DoctorProfile $doctorProfile): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        return match ($user->role) {
            'admin' => true,
            'patient' => $medicalDocument->patient_id === $user->id,
            'doctor' => $this->access->hasCareRelationship($user, $medicalDocument->patient_id, $ability, $doctorProfile),
            User::ROLE_ASSISTANT => $doctorProfile !== null
                && $this->access->hasCareRelationship($user, $medicalDocument->patient_id, $ability, $doctorProfile),
            default => false,
        };
    }

    private function isActive(User $user): bool
    {
        return $user->deactivated_at === null;
    }
}
