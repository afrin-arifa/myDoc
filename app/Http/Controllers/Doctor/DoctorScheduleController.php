<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\WaitlistEntry;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class DoctorScheduleController extends Controller
{
    /**
     * Longest custom start/end range the timetable loads, in days: six weeks, the size of the month grid.
     */
    public const MAX_RANGE_DAYS = 42;

    /**
     * Hours the timelines always show: doctors see patients until 11 PM.
     */
    private const TIMELINE_START_HOUR = 8;

    private const TIMELINE_END_HOUR = 23;

    public function index(Request $request, DoctorWorkspace $workspace): View
    {
        $profile = $workspace->forRequest($request, DoctorAccess::MANAGE_SCHEDULE)?->load(['user', 'specialties', 'availabilities.location', 'locations' => fn ($query) => $query->orderByDesc('is_active')->orderBy('name')]);
        abort_unless($profile, 403);

        // Links carry the chosen filters as one short value (filters=available,booked); the filter form sends a list.
        if (is_string($request->query('filters'))) {
            $request->merge(['filters' => explode(',', $request->query('filters'))]);
        }

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date', 'after_or_equal:start'],
            'view' => ['nullable', 'in:day,week,month'],
            'filters' => ['sometimes', 'array'],
            'filters.*' => ['in:available,held,blocked,booked,confirmed,completed,cancelled,rejected'],
        ]);
        $timezone = config('app.timezone', 'UTC');
        $date = CarbonImmutable::parse(
            $validated['date'] ?? $validated['start'] ?? today($timezone)->toDateString(),
            $timezone
        )->startOfDay();
        $view = $validated['view'] ?? 'week';
        $rangeStart = match ($view) {
            'day' => $date->startOfDay(),
            'month' => $date->startOfMonth()->startOfWeek(Carbon::MONDAY),
            default => $date->startOfWeek(Carbon::MONDAY),
        };
        $rangeEnd = match ($view) {
            'day' => $date->endOfDay(),
            'month' => $date->endOfMonth()->endOfWeek(Carbon::SUNDAY),
            default => $date->endOfWeek(Carbon::SUNDAY),
        };
        if (isset($validated['start'], $validated['end'])) {
            $customStart = CarbonImmutable::parse($validated['start'], $timezone)->startOfDay();
            $customEnd = CarbonImmutable::parse($validated['end'], $timezone)->endOfDay();

            // A longer range is not loaded: the selected view (a week by default) around the start date is shown
            // instead, with a notice, rather than redirecting away from the timetable.
            if ((int) $customStart->diffInDays($customEnd->startOfDay()) + 1 > self::MAX_RANGE_DAYS) {
                $request->session()->now('warning', 'The timetable can show at most '.self::MAX_RANGE_DAYS.' days at a time, so the '.$view.' view for your chosen date is shown instead. Choose a shorter range to see other dates.');
            } else {
                $rangeStart = $customStart;
                $rangeEnd = $customEnd;
            }
        }
        // The month grid only shows counts per day, so its slots are loaded without appointments or locations;
        // the selected day's appointments are loaded on their own below.
        $isMonth = $view === 'month';
        $slots = AppointmentSlot::query()
            ->where('doctor_profile_id', $profile->id)
            ->whereBetween('starts_at', [$rangeStart, $rangeEnd])
            ->when($isMonth, fn ($query) => $query->without('location'), fn ($query) => $query->with(['appointments.patient:id,name']))
            ->orderBy('starts_at')
            ->get();
        // Each cast date is a new object on every access, so every slot's start is read once here.
        $slotsByDate = $slots->groupBy(fn (AppointmentSlot $slot): string => $slot->starts_at->setTimezone($timezone)->toDateString());
        $selectedSlots = $slotsByDate->get($date->toDateString(), new EloquentCollection);

        if ($isMonth) {
            $selectedSlots->load(['appointments.patient:id,name', 'location']);
        }

        $selectedAppointments = $selectedSlots
            ->flatMap(fn (AppointmentSlot $slot) => $slot->appointments->each(fn (Appointment $appointment) => $appointment->setRelation('slot', $slot)))
            ->values();
        $calendarEvents = $isMonth ? collect() : $slots->flatMap(function (AppointmentSlot $slot) use ($timezone): array {
            $startsAt = $slot->starts_at->toImmutable()->setTimezone($timezone);
            $event = [
                'slot_id' => $slot->id,
                'date' => $startsAt->toDateString(),
                'starts_at' => $startsAt,
                'ends_at' => $slot->ends_at->toImmutable()->setTimezone($timezone),
                'slot_status' => $slot->status,
                'location' => $slot->location?->name,
            ];

            if ($slot->appointments->isNotEmpty()) {
                return $slot->appointments->map(fn (Appointment $appointment): array => [
                    ...$event,
                    'id' => 'appointment-'.$appointment->id,
                    'type' => 'appointment',
                    'appointment_id' => $appointment->id,
                    'patient_name' => $appointment->patient?->name,
                    'consultation_type' => $appointment->consultation_type,
                    'appointment_status' => $appointment->status,
                    'filter_status' => match ($appointment->status) {
                        'completed' => 'confirmed',
                        'cancelled', 'rejected', Appointment::STATUS_EXPIRED => 'cancelled',
                        default => $appointment->status,
                    },
                    'can_confirm' => $appointment->status === 'booked',
                    'can_start' => $appointment->status === 'confirmed',
                ])->all();
            }

            // "held": offered to a waiting-list patient, not bookable by anyone else until the offer ends. A time
            // without an appointment that has already started can no longer be used, so it is not shown.
            if (! in_array($slot->status, ['available', 'held', 'blocked'], true) || ! $startsAt->isFuture()) {
                return [];
            }

            return [[
                ...$event,
                'id' => 'slot-'.$slot->id,
                'type' => $slot->status,
                'appointment_id' => null,
                'patient_name' => null,
                'consultation_type' => null,
                'appointment_status' => null,
                'filter_status' => $slot->status,
                'can_confirm' => false,
                'can_start' => false,
                'can_book' => $slot->status === 'available',
                'can_block' => $slot->status === 'available',
                'can_unblock' => $slot->status === 'blocked',
            ]];
        })->values();
        $activeFilters = $validated['filters'] ?? ['available', 'held', 'blocked', 'booked', 'confirmed', 'completed', 'cancelled', 'rejected'];
        $summary = [
            'total slots' => $selectedSlots->count(),
            'appointments' => $selectedAppointments->count(),
            'confirmed' => $selectedAppointments->where('status', 'confirmed')->count(),
            'pending' => $selectedAppointments->where('status', 'booked')->count(),
            'cancelled' => $selectedAppointments->whereIn('status', Appointment::CLOSED_STATUSES)->count(),
            // Only times still to come can be booked or unblocked.
            'available' => $selectedSlots->filter(fn (AppointmentSlot $slot): bool => $slot->status === 'available' && $slot->starts_at->isFuture())->count(),
            'blocked' => $selectedSlots->filter(fn (AppointmentSlot $slot): bool => $slot->status === 'blocked' && $slot->starts_at->isFuture())->count(),
        ];

        // Waiting list tab: open entries in first-come order, and the open times staff could offer them.
        $waitlistEntries = $profile->waitlistEntries()
            ->whereIn('status', WaitlistEntry::OPEN_STATUSES)
            ->with(['patient:id,name,email,phone', 'location:id,name', 'pendingOffer.slot'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(100)
            ->get();
        $waitlistSlots = $waitlistEntries->isEmpty()
            ? collect()
            : AppointmentSlot::query()
                ->where('doctor_profile_id', $profile->id)
                ->where('status', 'available')
                ->where('starts_at', '>', now())
                ->where('starts_at', '<', CarbonImmutable::parse($waitlistEntries->max('date_to'), $timezone)->addDay())
                ->orderBy('starts_at')
                ->limit(500)
                ->get();

        return view('doctor.schedule', [
            'profile' => $profile,
            'waitlistEntries' => $waitlistEntries,
            'offerableSlots' => WaitlistEntry::offerableSlots($waitlistEntries->where('status', WaitlistEntry::STATUS_WAITING), $waitlistSlots, 30),
            'slots' => $slots,
            'selectedDate' => $date,
            'view' => $view,
            'slotsByDate' => $slotsByDate,
            'calendarEventsByDate' => $calendarEvents->whereIn('filter_status', $activeFilters)->groupBy('date'),
            'timelineHours' => $this->timelineHours($calendarEvents, $date, $view),
            'selectedAppointments' => $selectedAppointments,
            'summary' => $summary,
            'activeFilters' => $activeFilters,
        ]);
    }

    /**
     * First and last hour (0-24) of the day and week timelines: 8 AM to 11 PM, widened when the shown days have
     * earlier or later slots.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array{0: int, 1: int}
     */
    private function timelineHours(Collection $events, CarbonImmutable $date, string $view): array
    {
        $firstDay = ($view === 'day' ? $date : $date->startOfWeek(CarbonImmutable::MONDAY))->toDateString();
        $lastDay = ($view === 'day' ? $date : $date->startOfWeek(CarbonImmutable::MONDAY)->addDays(6))->toDateString();
        $shown = $events->whereBetween('date', [$firstDay, $lastDay]);

        if ($shown->isEmpty()) {
            return [self::TIMELINE_START_HOUR, self::TIMELINE_END_HOUR];
        }

        $start = (int) $shown->min(fn (array $event): int => $event['starts_at']->hour);
        $end = (int) $shown->max(function (array $event): int {
            $endsAt = $event['ends_at'];

            return $endsAt->toDateString() > $event['date'] ? 24 : (int) ceil(($endsAt->hour * 60 + $endsAt->minute) / 60);
        });

        return [min($start, self::TIMELINE_START_HOUR), max($end, self::TIMELINE_END_HOUR)];
    }
}
