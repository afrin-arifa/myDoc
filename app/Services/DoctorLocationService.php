<?php

namespace App\Services;

use App\Models\DoctorLocation;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manages a doctor's practice locations (chambers). The actor must be allowed to manage the doctor's schedule (the
 * doctor, or a Doctor Assistant assigned to them), and the location must belong to that doctor. Every change takes the
 * doctor's schedule lock first, like availability changes, so a location cannot be archived while another request is
 * attaching a weekly window to it.
 *
 * Audit events record only the location id and which fields changed: never the name, address or phone number.
 */
class DoctorLocationService
{
    public const NAME_TAKEN_MESSAGE = 'You already have a location with this name.';

    public const IN_USE_BY_ACTIVE_WINDOW_MESSAGE = 'This location is used by an active weekly schedule. Pause the schedule or move it to another location first.';

    public const IN_USE_MESSAGE = 'This location already has schedules or appointment slots, so it cannot be deleted. Archive it instead.';

    public const UNUSABLE_MESSAGE = 'Choose one of your active locations.';

    /**
     * @var list<string>
     */
    private const EDITABLE_FIELDS = ['name', 'address', 'area', 'phone', 'directions'];

    public function __construct(
        private readonly DoctorWorkspace $workspace,
        private readonly DoctorWorkspaceEventService $events,
    ) {}

    /**
     * @param  array{name: string, address: string, area?: string|null, phone?: string|null, directions?: string|null}  $attributes
     */
    public function create(User $actor, ?DoctorProfile $doctorProfile, array $attributes): DoctorLocation
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SCHEDULE);

        return DB::transaction(function () use ($actor, $doctorProfile, $attributes): DoctorLocation {
            DoctorProfile::lockForScheduling($doctorProfile->id);
            $this->ensureNameIsFree($doctorProfile, $attributes['name']);

            $location = $doctorProfile->locations()->create([...$this->editable($attributes), 'is_active' => true]);

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_LOCATION_CREATED, subject: $location, context: ['doctor_location_id' => $location->id]);

            return $location;
        });
    }

    /**
     * @param  array{name: string, address: string, area?: string|null, phone?: string|null, directions?: string|null}  $attributes
     */
    public function update(User $actor, DoctorLocation $location, array $attributes, ?DoctorProfile $doctorProfile = null): DoctorLocation
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SCHEDULE);
        $this->workspace->ensureBelongs($doctorProfile, $location->doctor_profile_id);

        return DB::transaction(function () use ($actor, $doctorProfile, $location, $attributes): DoctorLocation {
            DoctorProfile::lockForScheduling($doctorProfile->id);
            $locked = DoctorLocation::query()->whereKey($location->id)->lockForUpdate()->firstOrFail();
            $this->ensureNameIsFree($doctorProfile, $attributes['name'], ignoreId: $locked->id);

            $locked->fill($this->editable($attributes));
            $changed = array_values(array_intersect(self::EDITABLE_FIELDS, array_keys($locked->getDirty())));
            $locked->save();

            if ($changed !== []) {
                $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_LOCATION_UPDATED, subject: $locked, context: ['doctor_location_id' => $locked->id, 'changed_fields' => $changed]);
            }

            return $locked;
        });
    }

    /**
     * Archive (hide from new schedules) or restore a location. Archiving is refused while an active weekly window
     * still uses it; existing slots and appointments keep showing the location either way.
     */
    public function setActive(User $actor, DoctorLocation $location, bool $isActive, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SCHEDULE);
        $this->workspace->ensureBelongs($doctorProfile, $location->doctor_profile_id);

        DB::transaction(function () use ($actor, $doctorProfile, $location, $isActive): void {
            DoctorProfile::lockForScheduling($doctorProfile->id);
            $locked = DoctorLocation::query()->whereKey($location->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_active === $isActive) {
                return;
            }

            if (! $isActive && $locked->availabilities()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['location' => self::IN_USE_BY_ACTIVE_WINDOW_MESSAGE]);
            }

            $locked->update(['is_active' => $isActive]);

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_LOCATION_UPDATED, subject: $locked, context: [
                'doctor_location_id' => $locked->id,
                'changes' => ['is_active' => [! $isActive, $isActive]],
            ]);
        });
    }

    /**
     * Delete a location that was never used (for example one added by mistake). A location used by any weekly window
     * or slot is kept for history and can only be archived.
     */
    public function delete(User $actor, DoctorLocation $location, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SCHEDULE);
        $this->workspace->ensureBelongs($doctorProfile, $location->doctor_profile_id);

        DB::transaction(function () use ($actor, $doctorProfile, $location): void {
            DoctorProfile::lockForScheduling($doctorProfile->id);
            $locked = DoctorLocation::query()->whereKey($location->id)->lockForUpdate()->firstOrFail();

            if ($locked->availabilities()->exists() || $locked->appointmentSlots()->exists()) {
                throw ValidationException::withMessages(['location' => self::IN_USE_MESSAGE]);
            }

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_LOCATION_DELETED, context: ['doctor_location_id' => $locked->id]);
            $locked->delete();
        });
    }

    /**
     * The active location of this doctor with the given id, for attaching a weekly window to it. Call inside the
     * transaction that holds the doctor's schedule lock, so the location cannot be archived in between.
     *
     * @throws ValidationException When the id is missing, belongs to another doctor or is archived.
     */
    public function usableLocation(DoctorProfile $doctorProfile, int|string|null $locationId): DoctorLocation
    {
        $location = $locationId === null || $locationId === ''
            ? null
            : DoctorLocation::query()
                ->whereKey((int) $locationId)
                ->where('doctor_profile_id', $doctorProfile->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

        if ($location === null) {
            throw ValidationException::withMessages(['doctor_location_id' => self::UNUSABLE_MESSAGE]);
        }

        return $location;
    }

    private function ensureNameIsFree(DoctorProfile $doctorProfile, string $name, ?int $ignoreId = null): void
    {
        $taken = DoctorLocation::query()
            ->where('doctor_profile_id', $doctorProfile->id)
            ->where('name', trim($name))
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => self::NAME_TAKEN_MESSAGE]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    private function editable(array $attributes): array
    {
        $values = [];

        foreach (self::EDITABLE_FIELDS as $field) {
            $value = isset($attributes[$field]) ? trim((string) $attributes[$field]) : '';
            $values[$field] = $value === '' ? null : $value;
        }

        return $values;
    }
}
