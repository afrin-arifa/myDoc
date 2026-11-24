<?php

namespace App\Models;

use App\Models\Concerns\HasUrlKey;
use Database\Factories\WaitlistOfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A slot held for one waiting patient until expires_at. While the offer is pending the slot's status is "held", so
 * nobody else can book or block it; a slot can have only one pending offer (unique pending_slot_id).
 */
#[Fillable(['waitlist_entry_id', 'appointment_slot_id', 'status', 'expires_at', 'responded_at', 'offered_by'])]
class WaitlistOffer extends Model
{
    /** @use HasFactory<WaitlistOfferFactory> */
    use HasFactory, HasUrlKey;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_EXPIRED = 'expired';

    /**
     * Ended by staff, the patient leaving the list, the doctor switching the list off, or the slot reaching its time.
     */
    public const STATUS_WITHDRAWN = 'withdrawn';

    public function entry(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class, 'waitlist_entry_id');
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(AppointmentSlot::class, 'appointment_slot_id');
    }

    public function offeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_by');
    }

    /**
     * Pending and not yet expired: the patient may still accept it.
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }
}
