<?php

namespace App\Services;

use App\Models\MedicalDocument;
use App\Models\MedicalDocumentEvent;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Stores, serves and deletes patient medical documents on the private disk.
 *
 * Files get random server-side names under a fixed directory; nothing about the path comes from the client.
 * A file is written first and removed again if the database insert fails, so no orphan is left behind. A
 * document's file is deleted only after its row is deleted, and only at that row's own unique path.
 */
class MedicalDocumentService
{
    public const DISK = 'local';

    public const DIRECTORY = 'medical-documents';

    /**
     * Store a document for the patient. The uploader is the patient themselves unless staff upload it for them;
     * $inTransaction runs inside the same transaction, so a failure there also removes the stored file.
     *
     * @param  array{category: string, title?: string|null, description?: string|null, appointment_id?: int|null}  $details
     * @param  (callable(MedicalDocument): void)|null  $inTransaction
     */
    public function store(User $patient, UploadedFile $file, array $details, ?string $ipAddress = null, ?User $uploader = null, ?callable $inTransaction = null): MedicalDocument
    {
        $uploader ??= $patient;

        $mimeType = MedicalDocument::detectMimeType($file);
        $extension = (MedicalDocument::hasAcceptedContent($file) ? MedicalDocument::FILE_TYPES[$mimeType] ?? null : null)
            ?? throw new RuntimeException('Unsupported medical document type.');

        $path = $file->storeAs(self::DIRECTORY.'/'.$patient->id, Str::uuid()->toString().'.'.$extension, self::DISK);

        if ($path === false) {
            throw new RuntimeException('The medical document could not be stored.');
        }

        try {
            return DB::transaction(function () use ($patient, $uploader, $file, $details, $path, $mimeType, $ipAddress, $inTransaction): MedicalDocument {
                $document = new MedicalDocument;
                $document->forceFill([
                    'patient_id' => $patient->id,
                    'uploaded_by' => $uploader->id,
                    'appointment_id' => $details['appointment_id'] ?? null,
                    'category' => $details['category'],
                    'title' => $details['title'] ?? null,
                    'description' => $details['description'] ?? null,
                    'original_name' => $this->safeOriginalName($file),
                    'storage_path' => $path,
                    'mime_type' => $mimeType,
                    'size' => Storage::disk(self::DISK)->size($path),
                ])->save();

                $this->recordEvent($document, $uploader, MedicalDocumentEvent::ACTION_UPLOADED, $ipAddress);

                if ($inTransaction !== null) {
                    $inTransaction($document);
                }

                return $document;
            });
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }
    }

    /**
     * Stream the document as an attachment. Returns null (after logging the document id only) when the stored
     * file is missing, so callers can show a generic message.
     */
    public function download(MedicalDocument $document, User $actor, ?string $ipAddress = null): ?StreamedResponse
    {
        $disk = Storage::disk(self::DISK);

        if (! $this->isManagedPath($document->storage_path) || ! $disk->exists($document->storage_path)) {
            Log::warning('Medical document file is missing.', ['medical_document_id' => $document->id]);

            return null;
        }

        $this->recordEvent($document, $actor, MedicalDocumentEvent::ACTION_DOWNLOADED, $ipAddress);

        return $disk->download($document->storage_path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * Serve a validated image inline for thumbnails and the preview modal. The stored bytes are sniffed again and
     * must still be the image type recorded at upload; anything else (including every PDF) returns null, so only
     * genuine images are ever rendered by the browser. Previews are not audited: thumbnails load on every page
     * view, and the deliberate download action remains audited.
     */
    public function preview(MedicalDocument $document): ?StreamedResponse
    {
        $disk = Storage::disk(self::DISK);
        $path = $document->storage_path;

        if (! $document->isPreviewableImage() || ! $this->isManagedPath($path) || ! $disk->exists($path)) {
            return null;
        }

        if ((new \finfo(FILEINFO_MIME_TYPE))->file($disk->path($path)) !== $document->mime_type) {
            Log::warning('Medical document preview refused: stored content does not match its type.', ['medical_document_id' => $document->id]);

            return null;
        }

        return $disk->response($path, 'preview.'.MedicalDocument::FILE_TYPES[$document->mime_type], [
            'Content-Type' => $document->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ], 'inline');
    }

    public function delete(MedicalDocument $document, User $actor, ?string $ipAddress = null): void
    {
        $path = $document->storage_path;

        DB::transaction(function () use ($document, $actor, $ipAddress): void {
            $this->recordEvent($document, $actor, MedicalDocumentEvent::ACTION_DELETED, $ipAddress);
            $document->delete();
        });

        if ($this->isManagedPath($path)) {
            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($path));
        }
    }

    private function recordEvent(MedicalDocument $document, User $actor, string $action, ?string $ipAddress): void
    {
        $event = new MedicalDocumentEvent;
        $event->forceFill([
            'medical_document_id' => $document->id,
            'patient_id' => $document->patient_id,
            'actor_id' => $actor->id,
            'action' => $action,
            'ip_address' => $ipAddress,
        ])->save();
    }

    /**
     * Only paths this service generated may be read or deleted, never anything outside its directory.
     */
    private function isManagedPath(?string $path): bool
    {
        return is_string($path)
            && str_starts_with($path, self::DIRECTORY.'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, "\0");
    }

    /**
     * The client's filename is kept only as a display/download label: directory parts and control characters are
     * removed and the length is capped. It is never used to build a storage path.
     */
    private function safeOriginalName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', (string) $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim(Str::limit($name, 200, ''));

        return $name !== '' ? $name : 'document.'.(MedicalDocument::FILE_TYPES[(string) MedicalDocument::detectMimeType($file)] ?? 'bin');
    }
}
