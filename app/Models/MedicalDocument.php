<?php

namespace App\Models;

use App\Models\Concerns\HasUrlKey;
use App\Services\DoctorAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;

/**
 * A patient-owned medical document. The appointment link is optional metadata and never grants access.
 *
 * Every attribute is set explicitly by MedicalDocumentService; nothing is mass assignable, so ownership, storage
 * path, MIME type and size can never come from request input.
 */
class MedicalDocument extends Model
{
    use HasUrlKey;

    /**
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'prescription' => 'Prescription',
        'laboratory_report' => 'Laboratory report',
        'diagnostic_report' => 'Diagnostic report',
        'imaging_report' => 'Imaging report',
        'medical_certificate' => 'Medical certificate',
        'other' => 'Other medical document',
    ];

    /**
     * Icon shown with each type when a patient chooses one.
     *
     * @var array<string, string>
     */
    public const CATEGORY_ICONS = [
        'prescription' => 'fa-solid fa-prescription-bottle-medical',
        'laboratory_report' => 'fa-solid fa-flask',
        'diagnostic_report' => 'fa-solid fa-stethoscope',
        'imaging_report' => 'fa-solid fa-x-ray',
        'medical_certificate' => 'fa-solid fa-file-signature',
        'other' => 'fa-regular fa-file-lines',
    ];

    /**
     * Accepted file types: detected MIME type => stored file extension.
     *
     * @var array<string, string>
     */
    public const FILE_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Image types that may be shown inline (thumbnails and the preview modal). Everything else, notably PDF, is
     * only ever served as an attachment.
     *
     * @var list<string>
     */
    public const PREVIEWABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_KILOBYTES = 10240;

    /**
     * Appointment statuses that establish a doctor's legitimate access to a patient's medical records.
     * A cancelled or rejected booking on its own does not.
     *
     * @var list<string>
     */
    public const CARE_STATUSES = ['booked', 'confirmed', 'completed'];

    /**
     * The largest file that can actually be uploaded: the 10 MB application limit, or less when PHP's
     * upload_max_filesize / post_max_size are lower. PHP discards larger files before validation ever runs.
     */
    public static function maxUploadBytes(): int
    {
        return (int) min(self::MAX_KILOBYTES * 1024, UploadedFile::getMaxFilesize());
    }

    /**
     * The effective upload limit for people, e.g. "10 MB" or "2 MB".
     */
    public static function maxUploadLabel(): string
    {
        $megabytes = self::maxUploadBytes() / 1048576;

        return $megabytes >= 1 ? rtrim(rtrim(number_format($megabytes, 1), '0'), '.').' MB' : (int) floor(self::maxUploadBytes() / 1024).' KB';
    }

    protected $guarded = ['*'];

    protected $hidden = ['storage_path'];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MedicalDocumentEvent::class);
    }

    /**
     * Documents of patients who are (or were) in this doctor's care, decided from the patient's own appointment
     * history rather than from the document's optional appointment link.
     *
     * @param  Builder<MedicalDocument>  $query
     */
    public function scopeVisibleToDoctor(Builder $query, int $doctorProfileId): void
    {
        $query->visibleToDoctorProfiles([$doctorProfileId]);
    }

    /**
     * Documents of patients in the care of any of these doctors: at least one booked, confirmed or completed
     * appointment with one of them. Cancelled or rejected appointments alone never qualify. An empty list matches
     * nothing. Callers must pass doctor profiles the user is authorized for (see scopeVisibleToActor).
     *
     * @param  Builder<MedicalDocument>  $query
     * @param  list<int>  $doctorProfileIds
     */
    public function scopeVisibleToDoctorProfiles(Builder $query, array $doctorProfileIds): void
    {
        if ($doctorProfileIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('patient.appointments', fn (Builder $appointments) => $appointments
            ->whereIn('appointments.status', self::CARE_STATUSES)
            ->whereHas('slot', fn (Builder $slots) => $slots->whereIn('doctor_profile_id', $doctorProfileIds)));
    }

    /**
     * Documents a doctor or Doctor Assistant may see across every doctor they currently work for, as decided by
     * DoctorAccess (active accounts, current assignments, the view ability). Patients and admins match nothing here;
     * they have their own listings.
     *
     * @param  Builder<MedicalDocument>  $query
     */
    public function scopeVisibleToActor(Builder $query, User $actor): void
    {
        $query->visibleToDoctorProfiles(app(DoctorAccess::class)->profileIdsFor($actor, DoctorAccess::VIEW_MEDICAL_DOCUMENTS));
    }

    /**
     * MIME type detected from the file's actual bytes (never from its name or the client's claim).
     */
    public static function detectMimeType(UploadedFile $file): ?string
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_file($path)) {
            return null;
        }

        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($detected) ? $detected : null;
    }

    /**
     * True when the file's content is an accepted type and its extension matches that content.
     */
    public static function hasAcceptedContent(UploadedFile $file): bool
    {
        $detected = self::detectMimeType($file);
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $allowedExtensions = match ($detected) {
            'application/pdf' => ['pdf'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
            default => [],
        };

        return in_array($extension, $allowedExtensions, true);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? 'Medical document';
    }

    public function displayTitle(): string
    {
        return $this->title ?: $this->categoryLabel();
    }

    /**
     * True for validated image documents, which may be previewed inline. PDFs are never previewed.
     */
    public function isPreviewableImage(): bool
    {
        return in_array($this->mime_type, self::PREVIEWABLE_TYPES, true);
    }

    public function fileTypeLabel(): string
    {
        return strtoupper(self::FILE_TYPES[$this->mime_type] ?? 'file');
    }

    public function humanSize(): string
    {
        $size = (int) $this->size;

        return match (true) {
            $size >= 1048576 => number_format($size / 1048576, 1).' MB',
            $size >= 1024 => number_format($size / 1024, 0).' KB',
            default => $size.' B',
        };
    }

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
