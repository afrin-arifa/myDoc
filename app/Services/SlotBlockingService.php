<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentRescheduleHistory;
use App\Models\AppointmentSlot;
use App\Models\DoctorAvailability;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use App\Models\WaitlistOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Blocks and unblocks individual slots. The slot row is locked for the whole check-and-update, the same lock
 * booking and rescheduling take, so a slot can never be blocked and booked at the same time.
 *
 * The actor must be allowed to manage slots for the doctor context (the doctor, or an assistant assigned to them),
 * and the slot must belong to that doctor. The context may be omitted only by a doctor in their own workspace.
 */
class SlotBlockingService
{
    public function __construct(
        private readonly DoctorWorkspace $workspace,
        private readonly DoctorWorkspaceEventService $events,
        private readonly AppointmentSlotRelease $slotRelease,
    ) {}

    public function block(User $actor, AppointmentSlot $slot, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SLOTS);

        DB::transaction(function () use ($actor, $doctorProfile, $slot): void {
            $locked = $this->lockOwnedFutureSlot($doctorProfile, $slot);

            if ($locked->status !== 'available' || $this->hasActiveAppointment($locked)) {
                throw ValidationException::withMessages([
                    'slot' => 'Only free slots can be blocked. Slots with a booked or confirmed appointment must be rescheduled or cancelled instead.',
                ]);
            }

            $locked->update(['status' => 'blocked']);
            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_SLOT_BLOCKED, subject: $locked, fromStatus: 'available', toStatus: 'blocked', context: ['starts_at' => $locked->starts_at->toIso8601String()]);
        });
    }

    public function unblock(User $actor, AppointmentSlot $slot, ?DoctorProfile $doctorProfile = null): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SLOTS);

        DB::transaction(function () use ($actor, $doctorProfile, $slot): void {
            $locked = $this->lockOwnedFutureSlot($doctorProfile, $slot);

            if ($locked->status !== 'blocked') {
                throw ValidationException::withMessages(['slot' => 'This slot is not blocked.']);
            }

            $this->slotRelease->release($locked, AppointmentSlotRelease::REASON_UNBLOCKED, actor: $actor);
            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_SLOT_UNBLOCKED, subject: $locked, fromStatus: 'blocked', toStatus: 'available', context: ['starts_at' => $locked->starts_at->toIso8601String()]);
        });
    }

    /**
     * Close the doctor's future open slots that a weekly window used to cover and that no active weekly window covers
     * any more (the window was removed, paused, shortened or moved). Slots nobody ever used are deleted, so the
     * timetable shows only the new hours and the times can be generated again later. Slots that a record still refers
     * to (a closed appointment, a reschedule or a waiting list offer) are blocked instead, so that history is kept.
     *
     * Only slots that are "available", start in the future and have no booked or confirmed appointment are touched:
     * slots of pending, confirmed or completed appointments, already blocked slots and started or past slots are left
     * exactly as they are. Slots are locked in id order (the order rescheduling uses) and re-checked under the lock,
     * so a patient booking the same slot at the same moment either books it first (the slot is then skipped here) or
     * finds it gone. Nothing is ever unblocked here: blocked slots are only reopened by an explicit unblock, through
     * AppointmentSlotRelease.
     *
     * Call it inside the transaction that changes the availability, after the change and while holding the doctor's
     * schedule lock (DoctorProfile::lockForScheduling), so the active windows read here are final.
     *
     * @return array{removed: list<int>, blocked: list<int>} Slot ids.
     *
     * @throws LogicException When called outside a transaction.
     */
    public function closeUncoveredOpenSlots(DoctorProfile $doctorProfile, int $dayOfWeek, string $startTime, string $endTime): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Uncovered slots must be closed inside the transaction that changes the availability.');
        }

        $windowStart = DoctorAvailability::minuteOfDay($startTime);
        $windowEnd = DoctorAvailability::minuteOfDay($endTime);
        $activeWindows = DoctorAvailability::query()
            ->where('doctor_profile_id', $doctorProfile->id)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get();

        $candidateIds = AppointmentSlot::query()
            ->where('doctor_profile_id', $doctorProfile->id)
            ->where('status', 'available')
            ->where('starts_at', '>', now())
            ->get(['id', 'doctor_location_id', 'starts_at', 'ends_at'])
            ->filter(function (AppointmentSlot $slot) use ($dayOfWeek, $windowStart, $windowEnd, $activeWindows): bool {
                $slotStart = ($slot->starts_at->hour * 60) + $slot->starts_at->minute;
                $slotEnd = $slotStart + (int) $slot->starts_at->diffInMinutes($slot->ends_at);

                return $slot->starts_at->dayOfWeek === $dayOfWeek
                    && $slotStart < $windowEnd
                    && $slotEnd > $windowStart
                    && ! $activeWindows->contains(fn (DoctorAvailability $window): bool => $window->coversSlot($slot->starts_at, $slot->ends_at)
                        && $window->holdsSlotLocation($slot->doctor_location_id));
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $closed = ['removed' => [], 'blocked' => []];

        foreach (array_chunk($candidateIds, 500) as $chunk) {
            $locked = AppointmentSlot::query()->whereKey($chunk)->orderBy('id')->lockForUpdate()->get(['id', 'status', 'starts_at']);
            $held = Appointment::query()
                ->whereIn('appointment_slot_id', $chunk)
                ->whereIn('status', Appointment::ACTIVE_STATUSES)
                ->pluck('appointment_slot_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $toClose = $locked
                ->filter(fn (AppointmentSlot $slot): bool => $slot->status === 'available'
                    && $slot->starts_at->isFuture()
                    && ! in_array((int) $slot->id, $held, true))
                ->map(fn (AppointmentSlot $slot): int => (int) $slot->id)
                ->values()
                ->all();

            if ($toClose === []) {
                continue;
            }

            $toBlock = $this->slotsWithHistory($toClose);
            $toRemove = array_values(array_diff($toClose, $toBlock));

            AppointmentSlot::query()->whereKey($toBlock)->where('status', 'available')->update(['status' => 'blocked']);
            AppointmentSlot::query()->whereKey($toRemove)->where('status', 'available')->delete();
            $closed = ['removed' => [...$closed['removed'], ...$toRemove], 'blocked' => [...$closed['blocked'], ...$toBlock]];
        }

        return $closed;
    }

    /**
     * The slots that any appointment (whatever its status), reschedule or waiting list offer refers to.
     *
     * @param  list<int>  $slotIds
     * @return list<int>
     */
    private function slotsWithHistory(array $slotIds): array
    {
        return Appointment::query()->whereIn('appointment_slot_id', $slotIds)->pluck('appointment_slot_id')
            ->concat(AppointmentRescheduleHistory::query()->whereIn('previous_slot_id', $slotIds)->pluck('previous_slot_id'))
            ->concat(AppointmentRescheduleHistory::query()->whereIn('new_slot_id', $slotIds)->pluck('new_slot_id'))
            ->concat(WaitlistOffer::query()->whereIn('appointment_slot_id', $slotIds)->pluck('appointment_slot_id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function lockOwnedFutureSlot(DoctorProfile $doctorProfile, AppointmentSlot $slot): AppointmentSlot
    {
        $locked = AppointmentSlot::query()
            ->whereKey($slot->id)
            ->where('doctor_profile_id', $doctorProfile->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->starts_at->lte(CarbonImmutable::now())) {
            throw ValidationException::withMessages(['slot' => 'Past or started slots cannot be changed.']);
        }

        return $locked;
    }

    private function hasActiveAppointment(AppointmentSlot $slot): bool
    {
        return Appointment::query()
            ->where('appointment_slot_id', $slot->id)
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->exists();
    }
}
