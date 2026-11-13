<?php

namespace App\Http\Requests\MedicalDocument;

use App\Models\MedicalDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A patient uploads a document for themselves. There is no patient field: ownership is always the signed-in
 * patient. The file is checked by content (MIME sniffing) and by extension.
 */
class StoreMedicalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', MedicalDocument::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $types = array_values(array_unique([...array_values(MedicalDocument::FILE_TYPES), 'jpeg']));

        return [
            'document' => [
                'required',
                'file',
                'max:'.MedicalDocument::MAX_KILOBYTES,
                'mimetypes:'.implode(',', array_keys(MedicalDocument::FILE_TYPES)),
                'mimes:'.implode(',', $types),
                'extensions:'.implode(',', $types),
            ],
            'category' => ['required', 'string', Rule::in(array_keys(MedicalDocument::CATEGORIES))],
            'title' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'appointment_id' => [
                'nullable',
                'integer',
                Rule::exists('appointments', 'id')->where('patient_id', $this->user()?->id),
            ],
        ];
    }

    /**
     * Defence in depth: independently of the framework's MIME checks, sniff the actual bytes and require the
     * extension to match them, so disguised HTML, scripts or executables are always rejected.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('document') || ! $this->file('document') instanceof UploadedFile) {
                    return;
                }

                if (! MedicalDocument::hasAcceptedContent($this->file('document'))) {
                    $validator->errors()->add('document', 'Only PDF, JPG, PNG or WebP files can be uploaded.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.uploaded' => 'The file could not be uploaded. Files must be PDF, JPG, PNG or WebP and no larger than '.MedicalDocument::maxUploadLabel().'.',
            'document.max' => 'The file must not be larger than 10 MB.',
            'document.mimetypes' => 'Only PDF, JPG, PNG or WebP files can be uploaded.',
            'document.mimes' => 'Only PDF, JPG, PNG or WebP files can be uploaded.',
            'document.extensions' => 'Only PDF, JPG, PNG or WebP files can be uploaded.',
            'appointment_id.exists' => 'Choose one of your own appointments, or leave it blank.',
        ];
    }

    /**
     * @return array{category: string, title: string|null, description: string|null, appointment_id: int|null}
     */
    public function details(): array
    {
        return [
            'category' => $this->validated('category'),
            'title' => $this->validated('title'),
            'description' => $this->validated('description'),
            'appointment_id' => $this->validated('appointment_id') !== null ? (int) $this->validated('appointment_id') : null,
        ];
    }
}
