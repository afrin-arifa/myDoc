<?php

namespace App\Http\Requests\Doctor;

use App\Models\DoctorProfile;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GenerateDoctorSlotsRequest extends FormRequest
{
    /**
     * The longest date range, in calendar days including both ends, that may be previewed or generated at once.
     */
    public const MAX_RANGE_DAYS = 92;

    private ?DoctorProfile $resolvedDoctorProfile = null;

    /**
     * The doctor context must allow managing slots. A doctor who has not created a profile yet passes and gets the
     * existing "create your profile" response from the controller.
     */
    public function authorize(): bool
    {
        $this->resolvedDoctorProfile = app(DoctorWorkspace::class)->forRequest($this, DoctorAccess::MANAGE_SLOTS);

        return true;
    }

    /**
     * The doctor the slots are previewed or generated for, or null for a doctor without a profile yet.
     */
    public function doctorProfile(): ?DoctorProfile
    {
        return $this->resolvedDoctorProfile;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $requiresDates = $this->routeIs('doctor.slots.preview');

        return [
            'days' => ['nullable', 'integer', 'between:1,'.self::MAX_RANGE_DAYS],
            'start_date' => [$requiresDates ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'end_date' => [$requiresDates ? 'required' : 'nullable', 'required_with:start_date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'weekdays.required' => 'Select at least one weekday.',
            'weekdays.min' => 'Select at least one weekday.',
            'weekdays.*.distinct' => 'Each weekday can only be selected once.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->filled(['start_date', 'end_date'])) {
                    return;
                }

                [$startDate, $endDate] = $this->dateRange();

                if ((int) round($startDate->diffInDays($endDate)) + 1 > self::MAX_RANGE_DAYS) {
                    $validator->errors()->add(
                        'end_date',
                        'Slots can be generated for at most '.self::MAX_RANGE_DAYS.' days at a time.'
                    );
                }
            },
        ];
    }

    /**
     * The inclusive date range, at the start of each day in the application timezone.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function dateRange(): array
    {
        $timezone = config('app.timezone', 'UTC');
        $startDate = CarbonImmutable::parse($this->input('start_date') ?? today($timezone)->toDateString(), $timezone)->startOfDay();
        $endDate = $this->filled('end_date')
            ? CarbonImmutable::parse($this->input('end_date'), $timezone)->startOfDay()
            : $startDate->addDays($this->integer('days', 1) - 1);

        return [$startDate, $endDate];
    }

    /**
     * The explicitly selected ISO weekdays (Monday is 1, Sunday is 7). Validation guarantees at least one.
     *
     * @return list<int>
     */
    public function weekdays(): array
    {
        return array_values(array_map('intval', $this->validated('weekdays')));
    }
}
