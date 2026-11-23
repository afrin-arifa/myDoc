<?php

namespace App\Services;

use App\Jobs\OfferSlotToWaitlist;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\DoctorLocation;
use App\Models\DoctorProfile;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every waiting-list change except offering and expiry (WaitlistMatcher) and accepting (AppointmentBookingService,
 * which books the held slot through the open offer).
 *
 * Patients act only on their own entries and offers; staff act only in the workspace of a doctor they work for
 * (DoctorWorkspace + DoctorAccess). Ending an offer locks its slot, then the offer, then the entry, and the released
 * slot is offered to the next patient after the transaction commits.
 */
class WaitlistService
{
    public const LIMIT_MESSAGE = 'You can be on at most :limit waiting lists at a time. Leave one before joining another.';

    public const ALREADY_WAITING_MESSAGE = 'You are already on this doctor\'s waiting list.';

    public const UNAVAILABLE_MESSAGE = 'This doctor is not taking waiting list requests right now.';

    public function __construct(
        private readonly PatientAppointmentRules $rules,
        private readonly WaitlistMatcher $matcher,
        private readonly DoctorWorkspace $workspace,
        private readonly DoctorWorkspaceEventService $events,
    ) {}

    /**
     * @param  array{date_from: string, date_to: string, time_of_day?: string|null, doctor_location_id?: int|string|null, consultation_type: string, follow_up_of_id?: int|string|null}  $data
     *
     * @throws ValidationException
     */
    public function join(User $patient, DoctorProfile $doctor, array $data): WaitlistEntry
    {
        $entry = DB::transaction(function () use ($patient, $doctor, $data): WaitlistEntry {
            $this->rules->lockPatient($patient);
            $doctor->refresh();

            if (! $doctor->waitlist_enabled || ! $doctor->isPubliclyListed()) {
                throw ValidationException::withMessages(['waitlist' => self::UNAVAILABLE_MESSAGE]);
            }

            $open = WaitlistEntry::query()->where('patient_id', $patient->id)->whereIn('status', WaitlistEntry::OPEN_STATUSES);
            $limit = (int) config('booking.waitlist.max_active_entries', 3);

            if ((clone $open)->where('doctor_profile_id', $doctor->id)->exists()) {
                throw ValidationException::withMessages(['waitlist' => self::ALREADY_WAITING_MESSAGE]);
            }

            if ((clone $open)->count() >= $limit) {
                throw ValidationException::withMessages(['waitlist' => str_replace(':limit', (string) $limit, self::LIMIT_MESSAGE)]);
            }

            [$from, $to] = $this->validDates($data['date_from'], $data['date_to']);
            $locationId = $this->validLocation($doctor, $data['doctor_location_id'] ?? null);
            $original = $this->rules->followUpOriginal($patient, $data['follow_up_of_id'] ?? null, (int) $doctor->id);

            if (! in_array($data['consultation_type'], Appointment::bookableConsultationTypes(), true)) {
                throw ValidationException::withMessages(['consultation_type' => 'Choose a supported consultation type.']);
            }

            return WaitlistEntry::create([
                'patient_id' => $patient->id,
                'doctor_profile_id' => $doctor->id,
                'doctor_location_id' => $locationId,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'time_of_day' => ($data['time_of_day'] ?? null) ?: null,
                'consultation_type' => $data['consultation_type'],
                'follow_up_of_id' => $original?->id,
                'status' => WaitlistEntry::STATUS_WAITING,
            ]);
        });

        // A matching time may already be open: offer it right away instead of waiting for the next scheduler run.
        OfferSlotToWaitlist::dispatch(doctorProfileId: (int) $doctor->id)->afterCommit();

        return $entry;
    }

    /**
     * The patient leaves the list. An offer they were holding is withdrawn and goes to the next patient.
     */
    public function leave(User $patient, WaitlistEntry $entry): void
    {
        $this->ensureOwnEntry($patient, $entry);

        $releasedSlotId = DB::transaction(function () use ($entry): ?int {
            $pending = WaitlistOffer::query()->where('waitlist_entry_id', $entry->id)->where('status', WaitlistOffer::STATUS_PENDING)->first();
            $releasedSlotId = $pending === null ? null : $this->endPendingOffer($pending, WaitlistOffer::STATUS_WITHDRAWN);
            $locked = WaitlistEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if (in_array($locked->status, WaitlistEntry::OPEN_STATUSES, true)) {
                $locked->update(['status' => WaitlistEntry::STATUS_CANCELLED]);
            }

            return $releasedSlotId;
        });

        $this->offerNext($releasedSlotId);
    }

    /**
     * Resume an entry that was paused after unanswered offers.
     */
    public function resume(User $patient, WaitlistEntry $entry): void
    {
        $this->ensureOwnEntry($patient, $entry);

        $updated = WaitlistEntry::query()
            ->whereKey($entry->id)
            ->where('status', WaitlistEntry::STATUS_PAUSED)
            ->whereDate('date_to', '>=', today()->toDateString())
            ->update(['status' => WaitlistEntry::STATUS_WAITING, 'expired_offer_count' => 0, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw ValidationException::withMessages(['waitlist' => 'This waiting list request cannot be resumed.']);
        }

        OfferSlotToWaitlist::dispatch(doctorProfileId: (int) $entry->doctor_profile_id);
    }

    /**
     * The patient declines an offer: the slot goes to the next patient and they keep waiting.
     */
    public function decline(User $patient, WaitlistOffer $offer): void
    {
        $offer->loadMissing('entry');
        $this->ensureOwnEntry($patient, $offer->entry);

        $releasedSlotId = DB::transaction(function () use ($offer): ?int {
            $releasedSlotId = $this->endPendingOffer($offer, WaitlistOffer::STATUS_DECLINED);

            if ($releasedSlotId === null) {
                throw ValidationException::withMessages(['offer' => 'This offer is no longer open.']);
            }

            WaitlistEntry::query()->whereKey($offer->waitlist_entry_id)->where('status', WaitlistEntry::STATUS_OFFERED)
                ->update(['status' => WaitlistEntry::STATUS_WAITING, 'updated_at' => now()]);
            $this->events->recordSystem($offer->entry->doctor_profile_id, DoctorWorkspaceEvent::ACTION_WAITLIST_DECLINED, subject: AppointmentSlot::find($releasedSlotId), fromStatus: 'held', toStatus: 'available', context: ['source' => 'patient', 'waitlist_entry_id' => $offer->waitlist_entry_id, 'waitlist_offer_id' => $offer->id]);

            return $releasedSlotId;
        });

        $this->offerNext($releasedSlotId);
    }

    /**
     * Staff hold a chosen open slot of their doctor for a chosen waiting patient (same hold rules as automatic offers).
     */
    public function offerByStaff(User $actor, ?DoctorProfile $doctorProfile, WaitlistEntry $entry, int $slotId): WaitlistOffer
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::BOOK_APPOINTMENTS);
        $this->workspace->ensureBelongs($doctorProfile, $entry->doctor_profile_id);
        $this->workspace->ensureBelongs($doctorProfile, AppointmentSlot::query()->whereKey($slotId)->value('doctor_profile_id'));

        if ($entry->status !== WaitlistEntry::STATUS_WAITING) {
            throw ValidationException::withMessages(['entry' => 'This patient is not waiting for an offer right now.']);
        }

        $offer = $this->matcher->offerSlot($slotId, $entry, $actor);

        if ($offer === null) {
            throw ValidationException::withMessages(['slot_id' => 'This time cannot be offered: choose an open time in the patient\'s dates that starts late enough to hold, and check the patient has no other appointment then.']);
        }

        return $offer;
    }

    /**
     * Staff withdraw a pending offer; the slot is released to the next patient (never back to the same one).
     */
    public function withdrawByStaff(User $actor, ?DoctorProfile $doctorProfile, WaitlistOffer $offer): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::BOOK_APPOINTMENTS);
        $offer->loadMissing('entry');
        $this->workspace->ensureBelongs($doctorProfile, $offer->entry->doctor_profile_id);

        $releasedSlotId = DB::transaction(function () use ($actor, $doctorProfile, $offer): ?int {
            $releasedSlotId = $this->endPendingOffer($offer, WaitlistOffer::STATUS_WITHDRAWN);

            if ($releasedSlotId === null) {
                throw ValidationException::withMessages(['offer' => 'This offer is no longer open.']);
            }

            WaitlistEntry::query()->whereKey($offer->waitlist_entry_id)->where('status', WaitlistEntry::STATUS_OFFERED)
                ->update(['status' => WaitlistEntry::STATUS_WAITING, 'updated_at' => now()]);
            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_WAITLIST_WITHDRAWN, subject: AppointmentSlot::find($releasedSlotId), fromStatus: 'held', toStatus: 'available', context: ['source' => 'staff', 'waitlist_entry_id' => $offer->waitlist_entry_id, 'waitlist_offer_id' => $offer->id]);

            return $releasedSlotId;
        });

        $this->offerNext($releasedSlotId);
    }

    /**
     * The doctor's waiting list switch. Turning it off withdraws every pending offer (their slots become available)
     * and stops new requests and offers; waiting patients keep their place for when it is turned on again.
     */
    public function setEnabled(User $actor, ?DoctorProfile $doctorProfile, bool $enabled): void
    {
        $doctorProfile = $this->workspace->authorize($actor, $doctorProfile, DoctorAccess::MANAGE_SCHEDULE);

        DB::transaction(function () use ($actor, $doctorProfile, $enabled): void {
            DoctorProfile::lockForScheduling($doctorProfile->id);
            $doctorProfile->forceFill(['waitlist_enabled' => $enabled])->save();
            $withdrawn = 0;

            if (! $enabled) {
                $pending = WaitlistOffer::query()
                    ->where('status', WaitlistOffer::STATUS_PENDING)
                    ->whereHas('entry', fn ($entries) => $entries->where('doctor_profile_id', $doctorProfile->id))
                    ->orderBy('id')
                    ->get();

                foreach ($pending as $offer) {
                    if ($this->endPendingOffer($offer, WaitlistOffer::STATUS_WITHDRAWN) !== null) {
                        WaitlistEntry::query()->whereKey($offer->waitlist_entry_id)->where('status', WaitlistEntry::STATUS_OFFERED)
                            ->update(['status' => WaitlistEntry::STATUS_WAITING, 'updated_at' => now()]);
                        $withdrawn++;
                    }
                }
            }

            $this->events->record($actor, $doctorProfile, DoctorWorkspaceEvent::ACTION_WAITLIST_SETTINGS, context: ['waitlist_enabled' => $enabled, 'withdrawn_offer_count' => $withdrawn]);
        });

        if ($enabled) {
            OfferSlotToWaitlist::dispatch(doctorProfileId: (int) $doctorProfile->id)->afterCommit();
        }
    }

    /**
     * Lock the offer's slot, then the offer; if it is still pending, close it with $status and release the slot.
     * Returns the released slot id, or null when the offer was no longer pending.
     */
    private function endPendingOffer(WaitlistOffer $offer, string $status): ?int
    {
        AppointmentSlot::query()->whereKey($offer->appointment_slot_id)->lockForUpdate()->first();
        $locked = WaitlistOffer::query()->lockForUpdate()->find($offer->id);

        if ($locked === null || $locked->status !== WaitlistOffer::STATUS_PENDING) {
            return null;
        }

        $this->matcher->endOffer($locked, $status);

        return (int) $locked->appointment_slot_id;
    }

    private function offerNext(?int $slotId): void
    {
        if ($slotId !== null) {
            OfferSlotToWaitlist::dispatch(slotId: $slotId)->afterCommit();
        }
    }

    private function ensureOwnEntry(User $patient, ?WaitlistEntry $entry): void
    {
        abort_unless($entry !== null && (int) $entry->patient_id === (int) $patient->id, 404);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function validDates(string $from, string $to): array
    {
        $timezone = config('app.timezone');
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($to, $timezone)->startOfDay();
        $maxDays = (int) config('booking.waitlist.max_range_days', 14);

        if ($start->lt(CarbonImmutable::today($timezone)) || $end->lt($start) || (int) $start->diffInDays($end) + 1 > $maxDays) {
            throw ValidationException::withMessages(['date_to' => "Choose dates from today onwards, covering at most {$maxDays} days."]);
        }

        return [$start, $end];
    }

    private function validLocation(DoctorProfile $doctor, int|string|null $locationId): ?int
    {
        if ($locationId === null || $locationId === '') {
            return null;
        }

        $exists = DoctorLocation::query()->whereKey((int) $locationId)->where('doctor_profile_id', $doctor->id)->where('is_active', true)->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['doctor_location_id' => 'Choose one of this doctor\'s locations, or any location.']);
        }

        return (int) $locationId;
    }
}
