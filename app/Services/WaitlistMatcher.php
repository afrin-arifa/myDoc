<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Offers open slots to waiting-list patients, first come first served, and ends offers that ran out.
 *
 * Holding a slot locks the slot row, re-checks that it is still available and in the future, and creates the offer in
 * the same transaction; the unique pending_slot_id index guarantees one pending offer per slot even so. Lock order
 * everywhere: slot, then offer, then entry (booking locks the patient first, then the slot and the offer).
 *
 * An entry is skipped, keeping its place, while the patient cannot take the slot: deactivated account, at the active
 * appointment limit, or another active appointment at that time. The booking service re-checks all of this when the
 * patient accepts. A slot is never offered twice to the same entry.
 */
class WaitlistMatcher
{
    public function __construct(
        private readonly PatientAppointmentRules $rules,
        private readonly AppointmentNotificationService $notifications,
        private readonly DoctorWorkspaceEventService $events,
    ) {}

    /**
     * Offer this slot to the next eligible waiting patient, or to $entry when staff choose one. Returns the offer, or
     * null when the slot is not available, cannot be held long enough, or nobody eligible is waiting.
     */
    public function offerSlot(int $slotId, ?WaitlistEntry $entry = null, ?User $offeredBy = null): ?WaitlistOffer
    {
        try {
            return DB::transaction(function () use ($slotId, $entry, $offeredBy): ?WaitlistOffer {
                $slot = AppointmentSlot::query()->lockForUpdate()->find($slotId);

                if ($slot === null || $slot->status !== 'available') {
                    return null;
                }

                $doctor = DoctorProfile::query()->with('user')->find($slot->doctor_profile_id);
                $expiresAt = self::offerExpiry($slot);

                if ($doctor === null || ! $doctor->waitlist_enabled || ! $doctor->isPubliclyListed() || $expiresAt === null) {
                    return null;
                }

                $chosen = $entry !== null
                    ? $this->lockEntryIfEligible($entry->id, $slot)
                    : $this->nextEligibleEntry($slot);

                if ($chosen === null) {
                    return null;
                }

                $slot->update(['status' => 'held']);
                $offer = WaitlistOffer::create([
                    'waitlist_entry_id' => $chosen->id,
                    'appointment_slot_id' => $slot->id,
                    'status' => WaitlistOffer::STATUS_PENDING,
                    'expires_at' => $expiresAt,
                    'offered_by' => $offeredBy?->id,
                ]);
                $chosen->update(['status' => WaitlistEntry::STATUS_OFFERED]);
                $this->notifications->recordWaitlistOffer($offer);

                $context = ['source' => $offeredBy === null ? 'waitlist' : 'staff', 'slot_id' => $slot->id, 'waitlist_entry_id' => $chosen->id, 'waitlist_offer_id' => $offer->id, 'expires_at' => $expiresAt->toIso8601String()];
                $offeredBy === null
                    ? $this->events->recordSystem($doctor, DoctorWorkspaceEvent::ACTION_WAITLIST_OFFERED, subject: $slot, fromStatus: 'available', toStatus: 'held', context: $context)
                    : $this->events->record($offeredBy, $doctor, DoctorWorkspaceEvent::ACTION_WAITLIST_OFFERED, subject: $slot, fromStatus: 'available', toStatus: 'held', context: $context);

                return $offer;
            });
        } catch (UniqueConstraintViolationException) {
            // Another process created the pending offer for this slot at the same moment; it stands.
            return null;
        }
    }

    /**
     * Offer every open slot that a waiting patient asked for. A safety net behind the slot-release listener (new
     * slots from generation, missed events, offers that ended); each slot is handled in its own transaction.
     *
     * @return int Offers created.
     */
    public function offerOpenSlots(?int $doctorProfileId = null): int
    {
        $created = 0;
        $doctorIds = WaitlistEntry::query()
            ->where('status', WaitlistEntry::STATUS_WAITING)
            ->whereDate('date_to', '>=', today()->toDateString())
            ->when($doctorProfileId !== null, fn ($query) => $query->where('doctor_profile_id', $doctorProfileId))
            ->distinct()
            ->pluck('doctor_profile_id');

        foreach ($doctorIds as $doctorId) {
            $lastDay = WaitlistEntry::query()->where('doctor_profile_id', $doctorId)->where('status', WaitlistEntry::STATUS_WAITING)->max('date_to');
            $slotIds = AppointmentSlot::query()
                ->where('doctor_profile_id', $doctorId)
                ->where('status', 'available')
                ->where('starts_at', '>', now())
                ->where('starts_at', '<', CarbonImmutable::parse($lastDay, config('app.timezone'))->addDay())
                ->orderBy('starts_at')
                ->pluck('id');

            foreach ($slotIds as $slotId) {
                if (! WaitlistEntry::query()->where('doctor_profile_id', $doctorId)->where('status', WaitlistEntry::STATUS_WAITING)->exists()) {
                    break;
                }

                $created += (int) ($this->offerSlot((int) $slotId) !== null);
            }
        }

        return $created;
    }

    /**
     * End pending offers whose time ran out: the slot becomes available again (and is offered to the next patient),
     * and the entry waits again, or is paused after too many unanswered offers.
     *
     * @return list<int> Slot ids released.
     */
    public function expireDueOffers(): array
    {
        $released = [];

        foreach (WaitlistOffer::query()->where('status', WaitlistOffer::STATUS_PENDING)->where('expires_at', '<=', now())->orderBy('id')->get(['id', 'appointment_slot_id']) as $due) {
            $wasExpired = DB::transaction(function () use ($due): bool {
                AppointmentSlot::query()->whereKey($due->appointment_slot_id)->lockForUpdate()->first();
                $offer = WaitlistOffer::query()->with('entry')->lockForUpdate()->find($due->id);

                if ($offer === null || $offer->status !== WaitlistOffer::STATUS_PENDING || $offer->expires_at->isFuture()) {
                    return false;
                }

                $this->endOffer($offer, WaitlistOffer::STATUS_EXPIRED);
                $count = $offer->entry->expired_offer_count + 1;
                $offer->entry->update([
                    'expired_offer_count' => $count,
                    'status' => $count >= (int) config('booking.waitlist.pause_after_expired_offers', 2) ? WaitlistEntry::STATUS_PAUSED : WaitlistEntry::STATUS_WAITING,
                ]);
                $this->events->recordSystem($offer->entry->doctor_profile_id, DoctorWorkspaceEvent::ACTION_WAITLIST_OFFER_EXPIRED, subject: $offer->slot, fromStatus: 'held', toStatus: 'available', context: ['waitlist_entry_id' => $offer->entry->id, 'waitlist_offer_id' => $offer->id]);

                return true;
            });

            if ($wasExpired) {
                $released[] = (int) $due->appointment_slot_id;
            }
        }

        return $released;
    }

    /**
     * End entries whose last requested day has passed.
     */
    public function expirePastEntries(): int
    {
        return WaitlistEntry::query()
            ->whereIn('status', [WaitlistEntry::STATUS_WAITING, WaitlistEntry::STATUS_PAUSED])
            ->whereDate('date_to', '<', today()->toDateString())
            ->update(['status' => WaitlistEntry::STATUS_EXPIRED, 'updated_at' => now()]);
    }

    /**
     * Close a locked pending offer and make its slot available again (only if it is still held). Call inside the
     * transaction that locked the slot and then the offer.
     */
    public function endOffer(WaitlistOffer $offer, string $status): void
    {
        $offer->update(['status' => $status, 'responded_at' => now()]);
        AppointmentSlot::query()->whereKey($offer->appointment_slot_id)->where('status', 'held')->update(['status' => 'available', 'updated_at' => now()]);
    }

    /**
     * When an offer for this slot would end: after the hold window, but always cutoff_minutes before the slot starts.
     * Null when that leaves less than min_offer_minutes (the slot stays open for normal booking instead).
     */
    public static function offerExpiry(AppointmentSlot $slot): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $hold = $now->addMinutes((int) config('booking.waitlist.hold_minutes', 45));
        $latest = CarbonImmutable::instance($slot->starts_at)->subMinutes((int) config('booking.waitlist.cutoff_minutes', 30));
        $expiresAt = $hold->lt($latest) ? $hold : $latest;

        return $now->diffInMinutes($expiresAt, false) >= (int) config('booking.waitlist.min_offer_minutes', 15) ? $expiresAt : null;
    }

    private function nextEligibleEntry(AppointmentSlot $slot): ?WaitlistEntry
    {
        $day = $slot->starts_at->copy()->setTimezone(config('app.timezone'))->toDateString();
        $candidates = WaitlistEntry::query()
            ->where('doctor_profile_id', $slot->doctor_profile_id)
            ->where('status', WaitlistEntry::STATUS_WAITING)
            ->whereDate('date_from', '<=', $day)
            ->whereDate('date_to', '>=', $day)
            ->whereDoesntHave('offers', fn ($offers) => $offers->where('appointment_slot_id', $slot->id))
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id');

        foreach ($candidates as $entryId) {
            $entry = $this->lockEntryIfEligible((int) $entryId, $slot);

            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    private function lockEntryIfEligible(int $entryId, AppointmentSlot $slot): ?WaitlistEntry
    {
        $entry = WaitlistEntry::query()->with('patient')->lockForUpdate()->find($entryId);

        return $entry !== null
            && $entry->status === WaitlistEntry::STATUS_WAITING
            && $entry->wantsSlot($slot)
            && $this->patientCanTake($entry->patient, $slot)
                ? $entry
                : null;
    }

    private function patientCanTake(?User $patient, AppointmentSlot $slot): bool
    {
        return $patient !== null
            && $patient->role === 'patient'
            && $patient->deactivated_at === null
            && ! $this->rules->hasReachedActiveLimit($patient)
            && ($this->rules->overlappingAppointmentsFor($patient, [$slot])[$slot->id] ?? null) === null;
    }
}
