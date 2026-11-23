<?php

namespace App\Http\Controllers;

use App\Models\DoctorProfile;
use App\Models\Specialty;
use App\Models\WaitlistEntry;
use App\Services\PatientAppointmentRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Throwable;

class DoctorDirectoryController extends Controller
{
    /**
     * Days of availability offered in the profile's date navigation, starting today (Asia/Dhaka).
     */
    public const AVAILABILITY_DAYS = 14;

    /**
     * Sort options shown in the directory, keyed by the `sort` query value.
     *
     * @var array<string, string>
     */
    public const SORT_OPTIONS = [
        'name' => 'Name (A–Z)',
        'fee' => 'Lowest fee',
        'experience' => 'Most experienced',
    ];

    /**
     * The directory: one page of listed doctors with only the start of each doctor's next open slot (one aggregate
     * sub-query, never the slots themselves). "Available today" is a filter and can be combined with any sort; every
     * sort ends with name and id so pagination is stable. `filter=fee|experience` (older links) still sorts.
     */
    public function index(Request $request): View
    {
        Gate::authorize('browseDirectory', DoctorProfile::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'specialty' => ['nullable', 'integer', 'exists:specialties,id'],
            'filter' => ['nullable', 'in:all,today,fee,experience'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(self::SORT_OPTIONS))],
        ]);
        $legacyFilter = $validated['filter'] ?? null;
        $availableToday = $legacyFilter === 'today';
        $sort = $validated['sort'] ?? (in_array($legacyFilter, ['fee', 'experience'], true) ? $legacyFilter : 'name');

        $doctors = DoctorProfile::query()
            ->publiclyListed()
            ->join('users', 'doctor_profiles.user_id', '=', 'users.id')
            ->select('doctor_profiles.*')
            ->with(['user:id,name', 'specialties:id,name'])
            ->withMin(['appointmentSlots as next_available_at' => fn ($slots) => $slots->openForBooking()], 'starts_at')
            ->withCasts(['next_available_at' => 'immutable_datetime'])
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->whereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"))
                        ->orWhere('doctor_profiles.professional_title', 'like', "%{$search}%")
                        ->orWhereHas('specialties', fn ($specialties) => $specialties->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($validated['specialty'] ?? null, function ($query, $specialty): void {
                $query->whereHas('specialties', fn ($specialties) => $specialties->whereKey($specialty));
            })
            ->when($availableToday, fn ($query) => $query->whereHas('appointmentSlots', fn ($slots) => $slots
                ->openForBooking()
                ->whereDate('starts_at', CarbonImmutable::today())))
            ->when($sort === 'fee', fn ($query) => $query->orderBy('doctor_profiles.consultation_fee'))
            ->when($sort === 'experience', fn ($query) => $query->orderByDesc('doctor_profiles.years_of_experience'))
            ->orderBy('users.name')
            ->orderBy('doctor_profiles.id')
            ->paginate(12)
            ->withQueryString();

        return view('doctors.index', [
            'doctors' => $doctors,
            'specialties' => Specialty::query()->where('is_active', true)->orderBy('name')->get(),
            'availableToday' => $availableToday,
            'sort' => $sort,
            'sortOptions' => self::SORT_OPTIONS,
            'canBook' => $request->user()->role === 'patient',
        ]);
    }

    /**
     * A doctor's profile with availability for one selected day (Asia/Dhaka). Only that day's open slots are loaded,
     * plus one grouped count per day for the next AVAILABILITY_DAYS days. Without a valid `date`, the first day
     * with open times is shown. For patients, the active-limit and overlap rules are shown as read-only hints; the
     * booking service still enforces them.
     */
    public function show(Request $request, DoctorProfile $doctor, PatientAppointmentRules $rules): View
    {
        Gate::authorize('browseDirectory', DoctorProfile::class);
        abort_unless($doctor->isPubliclyListed(), 404);

        $doctor->load(['user:id,name', 'specialties:id,name']);

        // Patients choose among the doctor's active locations; an unknown or archived id simply shows all locations.
        $locations = $doctor->locations()->active()->orderBy('name')->get(['id', 'doctor_profile_id', 'name', 'address', 'area', 'phone', 'directions']);
        $selectedLocation = $locations->firstWhere('id', (int) $request->query('location'));
        $atSelectedLocation = fn ($slots) => $slots->when($selectedLocation, fn ($query) => $query->where('doctor_location_id', $selectedLocation->id));

        $today = CarbonImmutable::today();
        $windowEnd = $today->addDays(self::AVAILABILITY_DAYS);
        $openSlotsPerDay = $doctor->appointmentSlots()
            ->openForBooking()
            ->tap($atSelectedLocation)
            ->where('starts_at', '<', $windowEnd)
            ->selectRaw('DATE(starts_at) as day, COUNT(*) as total')
            ->groupByRaw('DATE(starts_at)')
            ->pluck('total', 'day');

        $days = collect(range(0, self::AVAILABILITY_DAYS - 1))->map(fn (int $offset) => [
            'date' => $date = $today->addDays($offset),
            'openSlots' => (int) ($openSlotsPerDay[$date->toDateString()] ?? 0),
        ]);

        $selectedDate = $this->requestedDate($request, $today)
            ?? data_get($days->firstWhere('openSlots', '>', 0), 'date')
            ?? $this->nextOpenSlotStart($doctor, $windowEnd, $selectedLocation?->id)?->startOfDay()
            ?? $today;

        $slots = $doctor->appointmentSlots()
            ->openForBooking()
            ->tap($atSelectedLocation)
            ->with('location:id,name,address,area')
            ->where('starts_at', '>=', $selectedDate)
            ->where('starts_at', '<', $selectedDate->addDay())
            ->orderBy('starts_at')
            ->get();

        $patient = $request->user()->role === 'patient' ? $request->user() : null;
        // Follow-up mode: only the patient's own completed appointment with this doctor; anything else is ignored.
        $followUpOf = $patient !== null ? $rules->followUpOriginalFor($patient, $request->query('follow_up'), (int) $doctor->id) : null;

        $waitlistEntry = $patient !== null
            ? $doctor->waitlistEntries()->where('patient_id', $patient->id)->whereIn('status', WaitlistEntry::OPEN_STATUSES)->first()
            : null;

        return view('doctors.show', [
            'followUpOf' => $followUpOf,
            'waitlistEntry' => $waitlistEntry,
            'canJoinWaitlist' => $patient !== null && $doctor->waitlist_enabled && $waitlistEntry === null,
            'doctor' => $doctor,
            'days' => $days,
            'selectedDate' => $selectedDate,
            'slots' => $slots,
            'nextOpenSlotStart' => $slots->isEmpty() ? $this->nextOpenSlotStart($doctor, $selectedDate->addDay(), $selectedLocation?->id) : null,
            'locations' => $locations,
            'selectedLocation' => $selectedLocation,
            'canBook' => $patient !== null,
            'hasReachedLimit' => $patient !== null && $rules->hasReachedActiveLimit($patient),
            'activeLimit' => PatientAppointmentRules::activeLimit(),
            'overlaps' => $patient !== null ? $rules->overlappingAppointmentsFor($patient, $slots) : [],
        ]);
    }

    /**
     * The `date` query value as a day in the application timezone, if it is a valid date that is not in the past.
     * Anything else falls back to the default day instead of showing an error.
     */
    private function requestedDate(Request $request, CarbonImmutable $today): ?CarbonImmutable
    {
        $value = $request->query('date');

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        return $date !== null && $date->toDateString() === $value && $date->gte($today) ? $date : null;
    }

    private function nextOpenSlotStart(DoctorProfile $doctor, CarbonImmutable $from, ?int $locationId = null): ?CarbonImmutable
    {
        $startsAt = $doctor->appointmentSlots()
            ->openForBooking()
            ->when($locationId !== null, fn ($query) => $query->where('doctor_location_id', $locationId))
            ->where('starts_at', '>=', $from)
            ->min('starts_at');

        return $startsAt !== null ? CarbonImmutable::parse($startsAt, config('app.timezone')) : null;
    }
}
