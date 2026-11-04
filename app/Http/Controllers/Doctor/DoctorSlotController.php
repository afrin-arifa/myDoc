<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Services\DoctorSlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DoctorSlotController extends Controller
{
    public function store(Request $request, DoctorSlotGenerator $generator): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'between:1,90'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'],
        ]);

        $profile = $request->user()->doctorProfile;

        if (! $profile) {
            return back()->withErrors(['profile' => 'Create your doctor profile before generating slots.']);
        }

        [$startDate, $endDate] = $this->dateRange($validated);
        $created = $generator->generateRange($profile, $startDate, $endDate, $validated['weekdays'] ?? null);

        return back()->with('status', "{$created} new appointment slot(s) generated.");
    }

    public function preview(Request $request, DoctorSlotGenerator $generator): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'],
        ]);
        $profile = $request->user()->doctorProfile;
        abort_unless($profile, 403);
        [$startDate, $endDate] = $this->dateRange($validated);

        return response()->json($generator->preview($profile, $startDate, $endDate, $validated['weekdays'] ?? null));
    }

    /**
     * @param  array{days?: int|null, start_date?: string|null, end_date?: string|null}  $validated
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function dateRange(array $validated): array
    {
        $timezone = config('app.timezone', 'UTC');
        $startDate = CarbonImmutable::parse($validated['start_date'] ?? today($timezone)->toDateString(), $timezone)->startOfDay();
        $endDate = isset($validated['end_date'])
            ? CarbonImmutable::parse($validated['end_date'], $timezone)->startOfDay()
            : $startDate->addDays(($validated['days'] ?? 1) - 1);

        return [$startDate, $endDate];
    }
}
