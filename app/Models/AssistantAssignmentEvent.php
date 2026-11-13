<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of assistant assignments. Events can be recorded but never changed or deleted, and they
 * deliberately do not reference the assignment row, which is deleted when an assignment is removed.
 */
class AssistantAssignmentEvent extends Model
{
    public const ACTION_ASSIGNED = 'assigned';

    public const ACTION_REMOVED = 'removed';

    /**
     * @var list<string>
     */
    public const ACTIONS = [self::ACTION_ASSIGNED, self::ACTION_REMOVED];

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (! in_array($event->action, self::ACTIONS, true)) {
                throw new LogicException('Unknown assistant assignment action.');
            }
        });

        static::updating(fn () => throw new LogicException('Assistant assignment history cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Assistant assignment history cannot be deleted.'));
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_id');
    }

    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
