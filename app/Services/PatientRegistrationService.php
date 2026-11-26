<?php

namespace App\Services;

use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Patient accounts a doctor or their assistant creates at the clinic.
 *
 * The account gets a one-time password that staff hand to the patient. It is stored only as a hash, is shown to
 * staff once, stops working after PASSWORD_LIFETIME_HOURS and must be replaced at first sign-in. Until then staff
 * of the same doctor may issue a new one; once the patient has chosen their own password, staff never can.
 */
class PatientRegistrationService
{
    public const PASSWORD_LIFETIME_HOURS = 48;

    /**
     * Letters and digits that cannot be confused with each other when read aloud or copied by hand.
     */
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private readonly DoctorWorkspaceEventService $events) {}

    /**
     * @return array{0: User, 1: string} The new patient and their one-time password.
     */
    public function register(User $actor, DoctorProfile $doctorProfile, string $name, string $email, ?string $phone): array
    {
        $password = $this->oneTimePassword();

        $patient = DB::transaction(function () use ($actor, $doctorProfile, $name, $email, $phone, $password): User {
            $patient = new User;
            $patient->fill(['name' => Str::squish($name), 'email' => Str::lower(trim($email)), 'phone' => $phone]);
            $patient->forceFill([
                'role' => 'patient',
                'password' => $password,
                'password_change_required' => true,
                'temporary_password_expires_at' => now()->addHours(self::PASSWORD_LIFETIME_HOURS),
                'registered_by_doctor_profile_id' => $doctorProfile->id,
                'registered_by' => $actor->id,
            ])->save();

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_PATIENT_REGISTERED, context: [
                'patient_id' => $patient->id,
                'patient_name' => $patient->name,
            ]);

            return $patient;
        });

        return [$patient, $password];
    }

    /**
     * A fresh one-time password for a patient this doctor's staff registered who has not set their own yet.
     *
     * @throws LogicException When the patient was not registered by this doctor or already chose a password.
     */
    public function issueNewPassword(User $actor, DoctorProfile $doctorProfile, User $patient): string
    {
        if (! $this->canIssueNewPassword($doctorProfile, $patient)) {
            throw new LogicException('A one-time password can only be issued before the patient sets their own.');
        }

        $password = $this->oneTimePassword();

        DB::transaction(function () use ($actor, $doctorProfile, $patient, $password): void {
            $patient->forceFill([
                'password' => $password,
                'temporary_password_expires_at' => now()->addHours(self::PASSWORD_LIFETIME_HOURS),
            ])->save();

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_PATIENT_PASSWORD_RESET, context: [
                'patient_id' => $patient->id,
                'patient_name' => $patient->name,
            ]);
        });

        return $password;
    }

    public function canIssueNewPassword(DoctorProfile $doctorProfile, User $patient): bool
    {
        return $patient->role === 'patient'
            && $patient->deactivated_at === null
            && $patient->mustChangePassword()
            && (int) $patient->registered_by_doctor_profile_id === (int) $doctorProfile->id;
    }

    /**
     * Ten characters in two groups (for example "Kp7mR-x3QwZ"), about 57 bits of randomness.
     */
    private function oneTimePassword(): string
    {
        $characters = collect(range(1, 10))->map(fn (): string => self::PASSWORD_ALPHABET[random_int(0, strlen(self::PASSWORD_ALPHABET) - 1)]);

        return $characters->take(5)->join('').'-'.$characters->slice(5)->join('');
    }
}
