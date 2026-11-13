<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['medical_document_id', 'patient_id', 'actor_id', 'action', 'ip_address'])]
class MedicalDocumentEvent extends Model
{
    public const ACTION_UPLOADED = 'uploaded';

    public const ACTION_DOWNLOADED = 'downloaded';

    public const ACTION_DELETED = 'deleted';

    public const UPDATED_AT = null;

    public function document(): BelongsTo
    {
        return $this->belongsTo(MedicalDocument::class, 'medical_document_id');
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
