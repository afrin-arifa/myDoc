<?php

namespace App\Services;

use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, updates and deletes a doctor's weekly availability. The actor must be allowed to manage the schedule for
 * the doctor context, the availability must belong to that doctor, and each change is recorded as a doctor
 * workspace event in the same transaction.
 *
 * Every change takes the doctor's schedule lock first (DoctorProfile::lockForScheduling), then re-checks that no two
 * windows of the doctor overlap on the same weekday (adjacent windows, such as 09:00–12:00 and 12:00–14:00, are
 * allowed). Two simultaneous requests therefore cannot both add overlapping windows. The form request performs the
 * same check earlier for quick feedback; this one is authoritative.
 *
 * When an update or deletion leaves future open slots no longer covered by any active window, those slots are
 * removed, or blocked when they have history (SlotBlockingService::closeUncoveredOpenSlots), and both numbers are
 * recorded on the event.
 *
 * Each window is held at one of the doctor's active locations (DoctorLocationService::usableLocation, checked under
 * the same lock). The window overlap rule still applies across locations: a doctor cannot be in two chambers at once.
 * Moving a window to another location closes the open slots generated for the old one.
 */
class DoctorAvailabilityService
{
    /**
     * Fields recorded in audit events. All are non-sensitive schedule settings.
     *
     * @var list<string>
     */
    private const AUDITED_FIELDS = ['day_of_week', 'start_time', 'end_time', 'is_active', 'doctor_location_id'];

    /**
     * Shown when a window would overlap another window of the same doctor on the same weekday.
     */
    public const OVERLAP_MESSAGE = 'This availability overlaps an existing schedule.';

    public function __construct(
        private readonly DoctorWorkspace $workspace,
        private readonly DoctorWorkspaceEventService $events,
        private readonly SlotBlockingService $slotBlocking,
        private readonly DoctorLocationService $locations,
    ) {}

    /**
     * @param  array{day_of_week: int|string, start_time: string, end_time: string, doctor_location_id: int|string}  $attributes
     */
    public function create(User $actor, DoctorProfile $doctorProfile, array $attributes, bool $isActive): DoctorAvailability
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SCHEDULE);

        return DB::transaction(function () use ($actor, $doctorProfile, $attributes, $isActive): DoctorAvailability {
            DoctorProfile::lockForScheduling($doctorProfile->id);
            $location = $this->locations->usableLocation($doctorProfile, $attributes['doctor_location_id'] ?? null);
            $this->ensureNoOverlappingWindow($doctorProfile, $attributes);

            $availability = $doctorProfile->availabilities()->create([
                ...$attributes,
                'doctor_location_id' => $location->id,
                'slot_duration_minutes' => DoctorAvailability::SLOT_DURATION_MINUTES,
                'is_active' => $isActive,
            ]);

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_AVAILABILITY_CREATED, subject: $availability, context: $this->snapshot($availability));

            return $availability;
        });
    }

    /**
     * Returns the number of future open slots closed because the old window no longer covers them. Without a
     * doctor_location_id the window keeps its current location.
     *
     * @param  array{day_of_week: int|string, start_time: string, end_time: string, doctor_location_id?: int|string}  $attributes
     */
    public function update(User $actor, DoctorAvailability $availability, array $attributes, bool $isActive, ?DoctorProfile $doctorProfile = null): int
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SCHEDULE);
        $this->workspace->ensureBelongs($doctorProfile, $availability->doctor_profile_id);

        return DB::transaction(function () use ($actor, $doctorProfile, $availability, $attributes, $isActive): int {
            DoctorProfile::lockForScheduling($doctorProfile->id);
            $locked = DoctorAvailability::query()->whereKey($availability->id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($locked);
            if (array_key_exists('doctor_location_id', $attributes)) {
                $attributes['doctor_location_id'] = $this->locations->usableLocation($doctorProfile, $attributes['doctor_location_id'])->id;
            }
            $this->ensureNoOverlappingWindow($doctorProfile, $attributes, ignoreId: $locked->id);

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

            $closed = $this->closeUncoveredSlots($doctorProfile, $before);

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_AVAILABILITY_UPDATED, subject: $locked, context: [...$after, 'changes' => $changes, ...$this->closedContext($closed)]);

            return count($closed['removed']) + count($closed['blocked']);
        });
    }

    /**
     * Returns the number of future open slots closed because the removed window no longer covers them.
     */
    public function delete(User $actor, DoctorAvailability $availability, ?DoctorProfile $doctorProfile = null): int
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SCHEDULE);
        $this->workspace->ensureBelongs($doctorProfile, $availability->doctor_profile_id);

        return DB::transaction(function () use ($actor, $doctorProfile, $availability): int {
            DoctorProfile::lockForScheduling($doctorProfile->id);
            $locked = DoctorAvailability::query()->whereKey($availability->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->snapshot($locked);
            $locked->delete();

            $closed = $this->closeUncoveredSlots($doctorProfile, $snapshot);

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_AVAILABILITY_DELETED, subject: $locked, context: [...$snapshot, ...$this->closedContext($closed)]);

            return count($closed['removed']) + count($closed['blocked']);
        });
    }

    /**
     * Refuse a window that overlaps another window of the same doctor on the same weekday, whether that window is
     * active or paused (so reactivating a paused window can never create an overlap). Times are compared as minutes,
     * so stored "10:00:00" and submitted "10:00" compare correctly; touching windows are not overlaps.
     *
     * @param  array{day_of_week: int|string, start_time: string, end_time: string}  $attributes
     *
     * @throws ValidationException
     */
    private function ensureNoOverlappingWindow(DoctorProfile $doctorProfile, array $attributes, ?int $ignoreId = null): void
    {
        $start = DoctorAvailability::minuteOfDay((string) $attributes['start_time']);
        $end = DoctorAvailability::minuteOfDay((string) $attributes['end_time']);

        $overlaps = DoctorAvailability::query()
            ->where('doctor_profile_id', $doctorProfile->id)
            ->where('day_of_week', (int) $attributes['day_of_week'])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get(['start_time', 'end_time'])
            ->contains(fn (DoctorAvailability $window): bool => DoctorAvailability::minuteOfDay((string) $window->start_time) < $end
                && DoctorAvailability::minuteOfDay((string) $window->end_time) > $start);

        if ($overlaps) {
            throw ValidationException::withMessages(['start_time' => self::OVERLAP_MESSAGE]);
        }
    }

    /**
     * @param  array{day_of_week: int, start_time: string, end_time: string, is_active: bool, doctor_location_id: int|null}  $previousWindow
     * @return array{removed: list<int>, blocked: list<int>}
     */
    private function closeUncoveredSlots(DoctorProfile $doctorProfile, array $previousWindow): array
    {
        return $this->slotBlocking->closeUncoveredOpenSlots(
            $doctorProfile,
            $previousWindow['day_of_week'],
            $previousWindow['start_time'],
            $previousWindow['end_time'],
        );
    }

    /**
     * @param  array{removed: list<int>, blocked: list<int>}  $closed
     * @return array{removed_slot_count: int, blocked_slot_count: int, removed_slot_ids?: list<int>, blocked_slot_ids?: list<int>}
     */
    private function closedContext(array $closed): array
    {
        return [
            'removed_slot_count' => count($closed['removed']),
            'blocked_slot_count' => count($closed['blocked']),
            ...array_filter(['removed_slot_ids' => $closed['removed'], 'blocked_slot_ids' => $closed['blocked']]),
        ];
    }

    /**
     * @return array{day_of_week: int, start_time: string, end_time: string, is_active: bool, doctor_location_id: int|null}
     */
    private function snapshot(DoctorAvailability $availability): array
    {
        return [
            'day_of_week' => (int) $availability->day_of_week,
            'start_time' => substr((string) $availability->start_time, 0, 5),
            'end_time' => substr((string) $availability->end_time, 0, 5),
            'is_active' => (bool) $availability->is_active,
            'doctor_location_id' => $availability->doctor_location_id === null ? null : (int) $availability->doctor_location_id,
        ];
    }
}
