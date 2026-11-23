<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Patient-level booking rules that apply however an appointment is created or moved (patient self-booking, a doctor
 * or assistant booking on the patient's behalf, staff rescheduling):
 *  - at most config('booking.max_active_appointments_per_patient') pending or confirmed appointments that have not
 *    ended yet;
 *  - no two pending or confirmed appointments whose times overlap, with any doctor. Back-to-back appointments
 *    (one ends exactly when the next starts) are allowed;
 *  - a follow-up links only to a completed appointment of the same patient with the same doctor, and an original has
 *    at most one pending or confirmed follow-up at a time. Follow-ups obey every other rule above unchanged.
 *
 * Concurrency: callers must call lockPatient() first in their transaction, before locking any slot. The patient row
 * lock serialises every booking for the same patient, so two simultaneous requests cannot both pass these checks.
 * Lock order everywhere: (appointment) -> patient -> slot(s).
 */
class PatientAppointmentRules
{
    public const AUDIENCE_PATIENT = 'patient';

    public const AUDIENCE_STAFF = 'staff';

    public const FOLLOW_UP_INELIGIBLE_MESSAGE = 'A follow-up can only be booked for a completed appointment of the same patient with the same doctor.';

    /**
     * Lock the patient's user row for the rest of the transaction.
     *
     * @throws LogicException When called outside a transaction.
     */
    public function lockPatient(User $patient): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The patient must be locked inside the booking transaction.');
        }

        User::query()->whereKey($patient->id)->lockForUpdate()->first();
    }

    /**
     * The completed appointment a new follow-up will link to, or null when no follow-up was requested. Call after
     * lockPatient(): every follow-up of an original belongs to the same patient, so the patient lock serialises them
     * and two requests cannot both pass the "one active follow-up" check.
     *
     * @throws ValidationException When the original is not this patient's completed appointment with this doctor,
     *                             or already has a pending or confirmed follow-up.
     */
    public function followUpOriginal(User $patient, int|string|null $followUpOfId, int $doctorProfileId, string $errorKey = 'follow_up_of_id', string $audience = self::AUDIENCE_PATIENT): ?Appointment
    {
        if ($followUpOfId === null || $followUpOfId === '') {
            return null;
        }

        $original = $this->eligibleOriginal($patient, $followUpOfId, $doctorProfileId);

        if ($original === null) {
            throw ValidationException::withMessages([$errorKey => self::FOLLOW_UP_INELIGIBLE_MESSAGE]);
        }

        if ($this->hasActiveFollowUp($original)) {
            throw ValidationException::withMessages([$errorKey => $audience === self::AUDIENCE_PATIENT
                ? 'You already have a pending or confirmed follow-up for appointment '.$original->reference().'.'
                : 'This patient already has a pending or confirmed follow-up for appointment '.$original->reference().'.']);
        }

        return $original;
    }

    /**
     * Read-only version for booking pages: the original if a follow-up could be booked from it right now, else null.
     * The booking service still decides (followUpOriginal) when the request is sent.
     */
    public function followUpOriginalFor(User $patient, int|string|null $followUpOfId, int $doctorProfileId): ?Appointment
    {
        if ($followUpOfId === null || $followUpOfId === '' || ! ctype_digit((string) $followUpOfId)) {
            return null;
        }

        $original = $this->eligibleOriginal($patient, $followUpOfId, $doctorProfileId);

        return $original !== null && ! $this->hasActiveFollowUp($original) ? $original : null;
    }

    private function eligibleOriginal(User $patient, int|string $followUpOfId, int $doctorProfileId): ?Appointment
    {
        $original = Appointment::query()
            ->with('slot')
            ->whereKey((int) $followUpOfId)
            ->where('patient_id', $patient->id)
            ->first();

        return $original !== null
            && (int) $original->slot?->doctor_profile_id === $doctorProfileId
            && $original->canBeFollowedUp()
                ? $original
                : null;
    }

    private function hasActiveFollowUp(Appointment $original): bool
    {
        return Appointment::query()
            ->where('follow_up_of_id', $original->id)
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->exists();
    }

    /**
     * Refuse a new appointment for a patient who already holds the maximum number of active appointments.
     *
     * @throws ValidationException
     */
    public function ensureWithinActiveLimit(User $patient, string $errorKey, string $audience = self::AUDIENCE_PATIENT): void
    {
        $limit = self::activeLimit();

        if ($this->activeAppointments($patient)->count() < $limit) {
            return;
        }

        throw ValidationException::withMessages([$errorKey => $audience === self::AUDIENCE_PATIENT
            ? "You already have {$limit} active appointment requests (pending or confirmed). Cancel one, or wait until one has taken place, before booking another."
            : "This patient already has {$limit} active appointments (pending or confirmed), which is the maximum."]);
    }

    /**
     * Refuse a slot whose time overlaps another active appointment of the patient. $ignoreAppointmentId leaves out
     * the appointment being moved when rescheduling.
     *
     * @throws ValidationException
     */
    public function ensureNoOverlap(User $patient, AppointmentSlot $slot, string $errorKey, string $audience = self::AUDIENCE_PATIENT, ?int $ignoreAppointmentId = null): void
    {
        $overlapping = $this->activeAppointments($patient)
            ->when($ignoreAppointmentId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreAppointmentId))
            ->whereHas('slot', fn (Builder $slots) => $slots
                ->where('starts_at', '<', $slot->ends_at)
                ->where('ends_at', '>', $slot->starts_at))
            ->with('slot')
            ->first();

        if ($overlapping === null) {
            return;
        }

        $when = $overlapping->slot->starts_at->format('D, M j, Y g:i A');

        throw ValidationException::withMessages([$errorKey => $audience === self::AUDIENCE_PATIENT
            ? "You already have an appointment that overlaps this time ({$when}). Choose a different time."
            : "This patient already has an appointment that overlaps this time ({$when})."]);
    }

    /**
     * Read-only hint for booking pages: whether the patient already holds the maximum number of active appointments.
     * It takes no lock and never replaces ensureWithinActiveLimit(), which still decides inside the booking
     * transaction.
     */
    public function hasReachedActiveLimit(User $patient): bool
    {
        return $this->activeAppointments($patient)->count() >= self::activeLimit();
    }

    /**
     * Read-only hint for booking pages: for each of the given slots, the patient's active appointment that overlaps
     * it (same rule as ensureNoOverlap(): back-to-back is not an overlap). Slots without a conflict are left out.
     * Uses one query for all the slots. It never replaces ensureNoOverlap(), which still decides when booking.
     *
     * @param  iterable<AppointmentSlot>  $slots
     * @return array<int, Appointment> Overlapping appointment keyed by slot id.
     */
    public function overlappingAppointmentsFor(User $patient, iterable $slots): array
    {
        $slots = collect($slots);

        if ($slots->isEmpty()) {
            return [];
        }

        $appointments = $this->activeAppointments($patient)
            ->whereHas('slot', fn (Builder $query) => $query
                ->where('starts_at', '<', $slots->max('ends_at'))
                ->where('ends_at', '>', $slots->min('starts_at')))
            ->with('slot')
            ->get();

        $overlaps = [];

        foreach ($slots as $slot) {
            $overlapping = $appointments->first(fn (Appointment $appointment) => $appointment->slot->starts_at->lt($slot->ends_at)
                && $appointment->slot->ends_at->gt($slot->starts_at));

            if ($overlapping !== null) {
                $overlaps[$slot->id] = $overlapping;
            }
        }

        return $overlaps;
    }

    public static function activeLimit(): int
    {
        return max(1, (int) config('booking.max_active_appointments_per_patient', 3));
    }

    /**
     * Pending or confirmed appointments of the patient that have not ended yet.
     *
     * @return Builder<Appointment>
     */
    private function activeAppointments(User $patient): Builder
    {
        return Appointment::query()
            ->where('patient_id', $patient->id)
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->whereHas('slot', fn (Builder $slots) => $slots->where('ends_at', '>', now()));
    }
}
