<?php

namespace App\Services;

use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates, updates and deletes a doctor's weekly availability. The actor must be allowed to manage the schedule for
 * the doctor context, the availability must belong to that doctor, and each change is recorded as a doctor
 * workspace event in the same transaction.
 */
class DoctorAvailabilityService
{
    /**
     * Fields recorded in audit events. All are non-sensitive schedule settings.
     *
     * @var list<string>
     */
    private const AUDITED_FIELDS = ['day_of_week', 'start_time', 'end_time', 'is_active'];

    public function __construct(
        private readonly DoctorWorkspace $workspace,
        private readonly DoctorWorkspaceEventService $events,
    ) {}

    /**
     * @param  array{day_of_week: int|string, start_time: string, end_time: string}  $attributes
     */
    public function create(User $actor, DoctorProfile $doctorProfile, array $attributes, bool $isActive): DoctorAvailability
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SCHEDULE);

        return DB::transaction(function () use ($actor, $doctorProfile, $attributes, $isActive): DoctorAvailability {
            $availability = $doctorProfile->availabilities()->create([
                ...$attributes,
                'slot_duration_minutes' => DoctorAvailability::SLOT_DURATION_MINUTES,
                'is_active' => $isActive,
            ]);

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_AVAILABILITY_CREATED, subject: $availability, context: $this->snapshot($availability));

            return $availability;
        });
    }

    /**
     * @param  array{day_of_week: int|string, start_time: string, end_time: string}  $attributes
     */
    public function update(User $actor, DoctorAvailability $availability, array $attributes, bool $isActive, ?DoctorProfile $doctorProfile = null): DoctorAvailability
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SCHEDULE);
        $this->workspace->ensureBelongs($doctorProfile, $availability->doctor_profile_id);

        return DB::transaction(function () use ($actor, $doctorProfile, $availability, $attributes, $isActive): DoctorAvailability {
            $locked = DoctorAvailability::query()->whereKey($availability->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);

            $locked->update([
                ...$attributes,
                'slot_duration_minutes' => DoctorAvailability::SLOT_DURATION_MINUTES,
                'is_active' => $isActive,
            ]);

            $after = $this->snapshot($locked->fresh());
            $changes = [];
            foreach (self::AUDITED_FIELDS as $field) {
                if ($before[$field] !== $after[$field]) {
                    $changes[$field] = [$before[$field], $after[$field]];
                }
            }

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_AVAILABILITY_UPDATED, subject: $locked, context: [...$after, 'changes' => $changes]);

            return $locked;
        });
    }

    public function delete(User $actor, DoctorAvailability $availability, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SCHEDULE);
        $this->workspace->ensureBelongs($doctorProfile, $availability->doctor_profile_id);

        DB::transaction(function () use ($actor, $doctorProfile, $availability): void {
            $locked = DoctorAvailability::query()->whereKey($availability->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->snapshot($locked);
            $locked->delete();

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_AVAILABILITY_DELETED, subject: $locked, context: $snapshot);
        });
    }

    /**
     * @return array{day_of_week: int, start_time: string, end_time: string, is_active: bool}
     */
    private function snapshot(DoctorAvailability $availability): array
    {
        return [
            'day_of_week' => (int) $availability->day_of_week,
            'start_time' => substr((string) $availability->start_time, 0, 5),
            'end_time' => substr((string) $availability->end_time, 0, 5),
            'is_active' => (bool) $availability->is_active,
        ];
    }
}
