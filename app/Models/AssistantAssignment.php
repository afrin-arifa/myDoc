<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * An active assignment of a Doctor Assistant to a doctor (table doctor_assistant). Removing an assignment deletes
 * the row; its history is kept in AssistantAssignmentEvent.
 *
 * Only the assignment columns are fillable, because attaching through the relationships fills the pivot. Rows
 * are written only by application code, never from request input.
 */
class AssistantAssignment extends Pivot
{
    protected $table = 'doctor_assistant';

    public $incrementing = true;

    protected $fillable = ['doctor_profile_id', 'assistant_id', 'assigned_by'];

    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
