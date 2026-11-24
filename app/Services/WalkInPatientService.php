<?php

namespace App\Services;

use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Patient records for people who phone or walk in without an account. A record belongs to the practice that made it:
 * it is only ever matched among patients that doctor already has, so a name and number can never be used to find, or
 * book for, somebody else's patient. It has no email and an unknown password, so nobody can sign in with it. Once an
 * administrator adds an email, the patient can claim it with "Forgot password".
 */
class WalkInPatientService
{
    /**
     * This doctor's existing patient with the same mobile number and name, if any.
     */
    public function existingPatient(DoctorProfile $doctorProfile, string $name, string $phone): ?User
    {
        return User::query()
            ->activePatients()
            ->withAppointmentsForDoctor($doctorProfile->id)
            ->where('phone', $phone)
            ->orderByDesc('is_walk_in')
            ->orderBy('id')
            ->get()
            ->first(fn (User $patient): bool => $this->normalizedName($patient->name) === $this->normalizedName($name));
    }

    /**
     * The doctor's walk-in patient with this name and number, created when there is none. Call inside the booking
     * transaction, so a refused booking leaves no record behind.
     *
     * @throws LogicException When called outside a transaction.
     */
    public function findOrCreate(DoctorProfile $doctorProfile, string $name, string $phone, bool $wantsSms): User
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Walk-in patients must be created inside the transaction that books for them.');
        }

        // One booking at a time per doctor here, so the same person is not created twice by a double click.
        DoctorProfile::lockForScheduling($doctorProfile->id);
        $patient = $this->existingPatient($doctorProfile, $name, $phone);

        if ($patient?->is_walk_in !== true) {
            $patient = new User;
            $patient->fill(['name' => Str::squish($name), 'phone' => $phone]);
            $patient->forceFill([
                'role' => 'patient',
                'is_walk_in' => true,
                'email' => null,
                'password' => Str::password(64),
                'sms_notifications_enabled' => $wantsSms,
            ])->save();
        } elseif ($wantsSms && ! $patient->sms_notifications_enabled) {
            $patient->update(['sms_notifications_enabled' => true]);
        }

        return $patient;
    }

    private function normalizedName(string $name): string
    {
        return mb_strtolower(Str::squish($name));
    }
}
