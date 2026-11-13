<?php

namespace App\Services;

use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentEvent;
use App\Models\DoctorProfile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns Doctor Assistants to doctors and removes those assignments. Only active administrators may do this.
 *
 * Each change runs in one transaction that locks the assistant, writes the assignment row and its history event
 * together (so history is recorded exactly once, or not at all if anything fails), and records the admin as the
 * actor. Removing an assignment deletes its row, which revokes access on the assistant's next request; the history
 * event is kept.
 */
class AssistantAssignmentService
{
    /**
     * Assign the assistant to every given doctor. Only an active assistant can receive assignments, only active
     * doctors with a profile can be assigned, and a doctor already assigned is rejected rather than duplicated.
     *
     * @param  list<int>  $doctorProfileIds
     * @return int Number of doctors assigned.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function assign(User $admin, User $assistant, array $doctorProfileIds): int
    {
        $this->ensureAdmin($admin);
        $doctorProfileIds = array_values(array_unique(array_map('intval', $doctorProfileIds)));

        if ($doctorProfileIds === []) {
            throw ValidationException::withMessages(['doctor_profile_ids' => 'Choose at least one doctor.']);
        }

        return DB::transaction(function () use ($admin, $assistant, $doctorProfileIds): int {
            $locked = $this->lockAssistant($assistant);

            if ($locked->deactivated_at !== null) {
                throw ValidationException::withMessages(['doctor_profile_ids' => 'Only active assistants can be assigned to doctors.']);
            }

            $eligible = DoctorProfile::query()
                ->whereKey($doctorProfileIds)
                ->whereHas('user', fn ($doctors) => $doctors->where('role', 'doctor')->whereNull('deactivated_at'))
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            if (count($eligible) !== count($doctorProfileIds)) {
                throw ValidationException::withMessages(['doctor_profile_ids' => 'Only active doctors can be assigned.']);
            }

            $alreadyAssigned = $locked->assistantAssignments()->whereIn('doctor_profile_id', $doctorProfileIds)->exists();

            if ($alreadyAssigned) {
                throw ValidationException::withMessages(['doctor_profile_ids' => 'This assistant is already assigned to one of these doctors.']);
            }

            foreach ($doctorProfileIds as $doctorProfileId) {
                $locked->assignedDoctors()->attach($doctorProfileId, ['assigned_by' => $admin->id]);
                $this->record($locked, $doctorProfileId, AssistantAssignmentEvent::ACTION_ASSIGNED, $admin);
            }

            return count($doctorProfileIds);
        });
    }

    /**
     * Remove one assignment. A missing assignment is reported as not found and records nothing.
     *
     * @throws AuthorizationException
     */
    public function unassign(User $admin, User $assistant, DoctorProfile $doctorProfile): void
    {
        $this->ensureAdmin($admin);

        DB::transaction(function () use ($admin, $assistant, $doctorProfile): void {
            $locked = $this->lockAssistant($assistant);
            $assignment = AssistantAssignment::query()
                ->where('assistant_id', $locked->id)
                ->where('doctor_profile_id', $doctorProfile->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->remove($assignment, $admin);
        });
    }

    /**
     * Remove every assignment of the assistant, e.g. when the account is deactivated. Must run inside the caller's
     * transaction so the deactivation and the removals succeed or fail together.
     *
     * @return int Number of assignments removed.
     */
    public function removeAll(User $admin, User $assistant): int
    {
        $this->ensureAdmin($admin);

        $assignments = AssistantAssignment::query()->where('assistant_id', $assistant->id)->lockForUpdate()->get();
        $assignments->each(fn (AssistantAssignment $assignment) => $this->remove($assignment, $admin));

        return $assignments->count();
    }

    private function remove(AssistantAssignment $assignment, User $admin): void
    {
        AssistantAssignment::query()->whereKey($assignment->id)->delete();
        $this->record($assignment->assistant_id, $assignment->doctor_profile_id, AssistantAssignmentEvent::ACTION_REMOVED, $admin);
    }

    private function record(User|int $assistant, int $doctorProfileId, string $action, User $admin): void
    {
        $event = new AssistantAssignmentEvent;
        $event->forceFill([
            'assistant_id' => $assistant instanceof User ? $assistant->id : $assistant,
            'doctor_profile_id' => $doctorProfileId,
            'action' => $action,
            'actor_id' => $admin->id,
        ])->save();
    }

    private function lockAssistant(User $assistant): User
    {
        return User::query()->whereKey($assistant->id)->where('role', User::ROLE_ASSISTANT)->lockForUpdate()->firstOrFail();
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureAdmin(User $admin): void
    {
        if ($admin->role !== 'admin' || $admin->deactivated_at !== null) {
            throw new AuthorizationException('Only administrators can manage assistant assignments.');
        }
    }
}
