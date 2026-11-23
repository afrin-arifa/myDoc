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
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorScheduleController extends Controller
{
    /**
     * Longest custom start/end range the timetable loads, in days: six weeks, the size of the month grid.
     */
    public const MAX_RANGE_DAYS = 42;

    public function index(Request $request, DoctorWorkspace $workspace): View
    {
        $profile = $workspace->forRequest($request, DoctorAccess::MANAGE_SCHEDULE)?->load(['user', 'specialties', 'availabilities.location', 'locations' => fn ($query) => $query->orderByDesc('is_active')->orderBy('name')]);
        abort_unless($profile, 403);

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
        $slots = AppointmentSlot::query()
            ->where('doctor_profile_id', $profile->id)
            ->whereBetween('starts_at', [$rangeStart, $rangeEnd])
            ->with(['appointments.patient:id,name'])
            ->orderBy('starts_at')
            ->get();
        $selectedAppointments = $slots
            ->filter(fn (AppointmentSlot $slot): bool => $slot->starts_at->isSameDay($date))
            ->flatMap(function (AppointmentSlot $slot) {
                return $slot->appointments->map(function (Appointment $appointment) use ($slot): Appointment {
                    return $appointment->setRelation('slot', $slot);
                });
            })
            ->sortBy(fn (Appointment $appointment): string => $appointment->slot->starts_at->toDateTimeString())
            ->values();
        $selectedSlots = $slots->filter(fn (AppointmentSlot $slot): bool => $slot->starts_at->isSameDay($date));
        $calendarEvents = $slots->flatMap(function (AppointmentSlot $slot) use ($timezone): array {
            if ($slot->appointments->isNotEmpty()) {
                return $slot->appointments->map(function (Appointment $appointment) use ($slot, $timezone): array {
                    return [
                        'id' => 'appointment-'.$appointment->id,
                        'type' => 'appointment',
                        'appointment_id' => $appointment->id,
                        'slot_id' => $slot->id,
                        'date' => $slot->starts_at->timezone($timezone)->toDateString(),
                        'starts_at' => $slot->starts_at->timezone($timezone)->toIso8601String(),
                        'ends_at' => $slot->ends_at->timezone($timezone)->toIso8601String(),
                        'patient_name' => $appointment->patient?->name,
                        'consultation_type' => $appointment->consultation_type,
                        'appointment_status' => $appointment->status,
                        'slot_status' => $slot->status,
                        'location' => $slot->location?->name,
                        'filter_status' => match ($appointment->status) {
                            'completed' => 'confirmed',
                            'cancelled', 'rejected', Appointment::STATUS_EXPIRED => 'cancelled',
                            default => $appointment->status,
                        },
                        'can_confirm' => $appointment->status === 'booked',
                        'can_start' => $appointment->status === 'confirmed',
                    ];
                })->all();
            }

            // "held": offered to a waiting-list patient, not bookable by anyone else until the offer ends.
            if (! in_array($slot->status, ['available', 'held', 'blocked'], true)) {
                return [];
            }

            $isFuture = $slot->starts_at->isFuture();

            return [[
                'id' => 'slot-'.$slot->id,
                'type' => $slot->status,
                'appointment_id' => null,
                'slot_id' => $slot->id,
                'date' => $slot->starts_at->timezone($timezone)->toDateString(),
                'starts_at' => $slot->starts_at->timezone($timezone)->toIso8601String(),
                'ends_at' => $slot->ends_at->timezone($timezone)->toIso8601String(),
                'patient_name' => null,
                'consultation_type' => null,
                'appointment_status' => null,
                'slot_status' => $slot->status,
                'location' => $slot->location?->name,
                'filter_status' => $slot->status,
                'can_confirm' => false,
                'can_start' => false,
                'can_block' => $isFuture && $slot->status === 'available',
                'can_unblock' => $isFuture && $slot->status === 'blocked',
            ]];
        })->values();
        $activeFilters = $validated['filters'] ?? ['available', 'held', 'blocked', 'booked', 'confirmed', 'completed', 'cancelled', 'rejected'];
        $visibleCalendarEvents = $calendarEvents->filter(
            fn (array $event): bool => in_array($event['filter_status'], $activeFilters, true)
        )->values();
        $summary = [
            'total slots' => $selectedSlots->count(),
            'appointments' => $selectedAppointments->count(),
            'confirmed' => $selectedAppointments->where('status', 'confirmed')->count(),
            'pending' => $selectedAppointments->where('status', 'booked')->count(),
            'cancelled' => $selectedAppointments->whereIn('status', Appointment::CLOSED_STATUSES)->count(),
            'available' => $selectedSlots->where('status', 'available')->count(),
            'blocked' => $selectedSlots->where('status', 'blocked')->count(),
        ];

        // Waiting list tab: open entries in first-come order, and the open times staff could offer them.
        $waitlistEntries = $profile->waitlistEntries()
            ->whereIn('status', WaitlistEntry::OPEN_STATUSES)
            ->with(['patient:id,name,email', 'location:id,name', 'pendingOffer.slot'])
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
            'waitlistSlots' => $waitlistSlots,
            'slots' => $slots,
            'selectedDate' => $date,
            'view' => $view,
            'slotsByDate' => $slots->groupBy(fn (AppointmentSlot $slot): string => $slot->starts_at->toDateString()),
            'calendarEventsByDate' => $visibleCalendarEvents->groupBy('date'),
            'selectedAppointments' => $selectedAppointments,
            'summary' => $summary,
            'activeFilters' => $activeFilters,
            'pendingCount' => $this->pendingCount($profile->id),
        ]);
    }

    private function pendingCount(int $profileId): int
    {
        return Appointment::query()
            ->where('appointments.status', 'booked')
            ->whereHas('slot', fn ($query) => $query->where('doctor_profile_id', $profileId))
            ->count();
    }
}
