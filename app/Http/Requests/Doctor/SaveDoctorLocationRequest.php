<?php

namespace App\Http\Requests\Doctor;

use App\Models\DoctorProfile;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Creates or edits a practice location in a doctor's workspace. The doctor comes from the workspace (the signed-in
 * doctor, or the doctor in an assistant's URL), never from the form; DoctorLocationService checks it again.
 */
class SaveDoctorLocationRequest extends FormRequest
{
    /**
     * Digits with an optional leading "+", spaces or dashes: mobile and landline numbers are both accepted.
     */
    public const PHONE_PATTERN = '/^\+?[0-9][0-9 \-]{5,18}[0-9]$/';

    private ?DoctorProfile $resolvedDoctorProfile = null;

    public function authorize(): bool
    {
        $this->resolvedDoctorProfile = app(DoctorWorkspace::class)->forRequest($this, DoctorAccess::MANAGE_SCHEDULE);

        return $this->resolvedDoctorProfile !== null;
    }

    public function doctorProfile(): DoctorProfile
    {
        return $this->resolvedDoctorProfile;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:'.self::PHONE_PATTERN],
            'directions' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a phone number using digits, with an optional + and spaces or dashes.',
        ];
    }
}
