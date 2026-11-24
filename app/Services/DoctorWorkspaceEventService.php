<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorLocation;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Records doctor workspace audit events. This is the only way events are written.
 *
 *  - Events must be recorded inside the database transaction of the operation they describe, after the change is
 *    written, so a failed or rolled-back operation leaves no event behind.
 *  - The actor is the user object passed by the calling service (the signed-in user); there is no way to supply an
 *    actor id or role. The role is read from the database at the time of the event. System events (no user) go
 *    through recordSystem() and are stored with a null actor and the role "system".
 *  - The doctor context must exist, and the appointment / subject record must belong to it.
 *  - The actor must currently work for that doctor (doctor or assigned assistant, via DoctorAccess), or be the
 *    patient of the appointment for patient bookings and cancellations. Admins do not create workspace events.
 *  - Context accepts only an allow-list of non-sensitive keys with scalar values: never notes, reasons, names,
 *    contact details, documents, tokens or request payloads.
 */
class DoctorWorkspaceEventService
{
    /**
     * Context keys that may be stored. Anything else is a programming error and is rejected.
     *
     * @var list<string>
     */
    public const CONTEXT_KEYS = [
        'source', 'slot_id', 'starts_at', 'consultation_type',
        'previous_slot_id', 'new_slot_id', 'previous_starts_at', 'new_starts_at', 'reschedule_history_id',
        'slot_count', 'range_start', 'range_end', 'weekdays', 'slot_released',
        'day_of_week', 'start_time', 'end_time', 'is_active', 'changes',
        'blocked_slot_count', 'blocked_slot_ids', 'removed_slot_count', 'removed_slot_ids',
        'doctor_location_id', 'changed_fields',
        'follow_up_of_id', 'walk_in',
        'waitlist_entry_id', 'waitlist_offer_id', 'expires_at', 'waitlist_enabled', 'withdrawn_offer_count',
    ];

    /**
     * Actions a patient may record, and only for their own appointment.
     *
     * @var list<string>
     */
    private const PATIENT_ACTIONS = [DoctorWorkspaceEvent::ACTION_BOOKED, DoctorWorkspaceEvent::ACTION_CANCELLED];

    public function __construct(private readonly DoctorAccess $access) {}

    /**
     * Record an event performed by a signed-in user.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws AuthorizationException|LogicException
     */
    public function record(
        User $actor,
        DoctorProfile|int $doctorProfile,
        string $action,
        ?Appointment $appointment = null,
        ?Model $subject = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        array $context = [],
    ): DoctorWorkspaceEvent {
        return $this->write($actor, $doctorProfile, $action, $appointment, $subject, $fromStatus, $toStatus, $context);
    }

    /**
     * Record an event with no signed-in user (console commands, seeders, scheduled work).
     *
     * @param  array<string, mixed>  $context
     *
     * @throws LogicException
     */
    public function recordSystem(
        DoctorProfile|int $doctorProfile,
        string $action,
        ?Appointment $appointment = null,
        ?Model $subject = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        array $context = [],
    ): DoctorWorkspaceEvent {
        return $this->write(null, $doctorProfile, $action, $appointment, $subject, $fromStatus, $toStatus, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(
        ?User $actor,
        DoctorProfile|int $doctorProfile,
        string $action,
        ?Appointment $appointment,
        ?Model $subject,
        ?string $fromStatus,
        ?string $toStatus,
        array $context,
    ): DoctorWorkspaceEvent {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Workspace events must be recorded inside the transaction of the operation they describe.');
        }

        if (! in_array($action, DoctorWorkspaceEvent::ACTIONS, true)) {
            throw new LogicException("Unknown doctor workspace action [{$action}].");
        }

        $profileId = $doctorProfile instanceof DoctorProfile ? (int) $doctorProfile->id : $doctorProfile;

        if ($profileId <= 0 || ! DoctorProfile::query()->whereKey($profileId)->exists()) {
            throw new LogicException('Workspace events need an existing doctor profile.');
        }

        if (in_array($action, DoctorWorkspaceEvent::APPOINTMENT_ACTIONS, true) && $appointment === null) {
            throw new LogicException('Appointment events must reference the appointment.');
        }

        if ($appointment !== null && (int) $appointment->slot()->value('doctor_profile_id') !== $profileId) {
            throw new LogicException('The appointment does not belong to this doctor.');
        }

        [$subjectType, $subjectId] = $this->subject($subject ?? $appointment, $profileId);
        $actorRole = $actor === null ? DoctorWorkspaceEvent::SYSTEM_ACTOR : $this->authorizeActor($actor, $profileId, $action, $appointment);

        $event = new DoctorWorkspaceEvent;
        $event->forceFill([
            'doctor_profile_id' => $profileId,
            'actor_id' => $actor?->id,
            'actor_role' => $actorRole,
            'action' => $action,
            'appointment_id' => $appointment?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'context' => $this->sanitize($context),
            'ip_address' => $actor === null ? null : $this->requestIp(),
        ])->save();

        return $event;
    }

    /**
     * Returns the actor's role as stored in the database right now, after checking they may act for this doctor.
     *
     * @throws AuthorizationException
     */
    private function authorizeActor(User $actor, int $profileId, string $action, ?Appointment $appointment): string
    {
        $role = $actor->exists ? User::query()->whereKey($actor->id)->value('role') : null;

        $allowed = match ($role) {
            'doctor', User::ROLE_ASSISTANT => $this->access->worksFor($actor, $profileId),
            'patient' => in_array($action, self::PATIENT_ACTIONS, true)
                && $appointment !== null
                && (int) $appointment->patient_id === (int) $actor->id,
            default => false,
        };

        if (! $allowed) {
            throw new AuthorizationException('This user cannot record events for this doctor.');
        }

        return $role;
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    private function subject(?Model $subject, int $profileId): array
    {
        if ($subject === null) {
            return [null, null];
        }

        [$type, $owner] = match (true) {
            $subject instanceof Appointment => [DoctorWorkspaceEvent::SUBJECT_APPOINTMENT, $subject->slot()->value('doctor_profile_id')],
            $subject instanceof AppointmentSlot => [DoctorWorkspaceEvent::SUBJECT_SLOT, $subject->doctor_profile_id],
            $subject instanceof DoctorAvailability => [DoctorWorkspaceEvent::SUBJECT_AVAILABILITY, $subject->doctor_profile_id],
            $subject instanceof DoctorLocation => [DoctorWorkspaceEvent::SUBJECT_LOCATION, $subject->doctor_profile_id],
            default => throw new LogicException('Unsupported workspace event subject.'),
        };

        if ((int) $owner !== $profileId) {
            throw new LogicException('The subject record does not belong to this doctor.');
        }

        return [$type, (int) $subject->getKey()];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function sanitize(array $context): ?array
    {
        foreach ($context as $key => $value) {
            if (! in_array($key, self::CONTEXT_KEYS, true)) {
                throw new LogicException("Context key [{$key}] is not allowed in workspace events.");
            }

            if ($key === 'changes') {
                if (! is_array($value)) {
                    throw new LogicException('Context changes must be a list of field changes.');
                }
                foreach ($value as $field => $change) {
                    if (! in_array($field, self::CONTEXT_KEYS, true) || ! is_array($change) || count($change) !== 2
                        || ! $this->isScalarList(array_values($change))) {
                        throw new LogicException("Context change [{$field}] is not allowed in workspace events.");
                    }
                }

                continue;
            }

            if (! (is_scalar($value) || $value === null || (is_array($value) && array_is_list($value) && $this->isScalarList($value)))) {
                throw new LogicException("Context value for [{$key}] must be a scalar or a list of scalars.");
            }
        }

        return $context === [] ? null : $context;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function isScalarList(array $values): bool
    {
        foreach ($values as $value) {
            if (! (is_scalar($value) || $value === null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The client IP of the current web request, recorded for user actions as part of the security audit trail;
     * null outside HTTP requests (console, queue, direct service calls).
     */
    private function requestIp(): ?string
    {
        $request = app()->bound('request') ? request() : null;

        return $request?->route() !== null ? $request->ip() : null;
    }
}
