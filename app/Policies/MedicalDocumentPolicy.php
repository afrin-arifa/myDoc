<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\MedicalDocument;
use App\Models\User;

/**
 * Medical documents belong to the patient.
 *  - Patients manage (list, upload, download, delete) only their own documents.
 *  - Doctors may list and download documents of patients in their care: at least one booked, confirmed or
 *    completed appointment with that doctor, checked against the patient's appointment history (never the
 *    document's optional appointment link). Doctors cannot upload or delete.
 *  - Admins may list, inspect, download and delete every document.
 *  - Deactivated accounts and guests have no access.
 */
class MedicalDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActive($user) && in_array($user->role, ['patient', 'doctor', 'admin'], true);
    }

    public function create(User $user): bool
    {
        return $this->isActive($user) && $user->role === 'patient';
    }

    public function view(User $user, MedicalDocument $medicalDocument): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        return match ($user->role) {
            'admin' => true,
            'patient' => $medicalDocument->patient_id === $user->id,
            'doctor' => $this->doctorCaresForPatient($user, $medicalDocument->patient_id),
            default => false,
        };
    }

    public function download(User $user, MedicalDocument $medicalDocument): bool
    {
        return $this->view($user, $medicalDocument);
    }

    /**
     * Inline preview: the same people who may download the document, and only for validated image types.
     */
    public function preview(User $user, MedicalDocument $medicalDocument): bool
    {
        return $medicalDocument->isPreviewableImage() && $this->view($user, $medicalDocument);
    }

    public function delete(User $user, MedicalDocument $medicalDocument): bool
    {
        if (! $this->isActive($user)) {
            return false;
        }

        return $user->role === 'admin'
            || ($user->role === 'patient' && $medicalDocument->patient_id === $user->id);
    }

    private function doctorCaresForPatient(User $doctor, int $patientId): bool
    {
        $profileId = $doctor->doctorProfile()->value('id');

        return $profileId !== null && Appointment::query()
            ->where('patient_id', $patientId)
            ->whereIn('status', MedicalDocument::CARE_STATUSES)
            ->whereHas('slot', fn ($slots) => $slots->where('doctor_profile_id', $profileId))
            ->exists();
    }

    private function isActive(User $user): bool
    {
        return $user->deactivated_at === null;
    }
}
