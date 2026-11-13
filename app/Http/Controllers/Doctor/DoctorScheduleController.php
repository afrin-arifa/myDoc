<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorScheduleController extends Controller
{
    public function index(Request $request, DoctorWorkspace $workspace): View
    {
        $profile = $workspace->forRequest($request, DoctorAccess::MANAGE_SCHEDULE)?->load(['user', 'specialties', 'availabilities']);
        abort_unless($profile, 403);

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'start' => ['nullable', 'date'],
            'end' => ['nullable', 'date', 'after_or_equal:start'],
            'view' => ['nullable', 'in:day,week,month'],
            'filters' => ['sometimes', 'array'],
            'filters.*' => ['in:available,blocked,booked,confirmed,completed,cancelled,rejected'],
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
            $rangeStart = CarbonImmutable::parse($validated['start'], $timezone)->startOfDay();
            $rangeEnd = CarbonImmutable::parse($validated['end'], $timezone)->endOfDay();
        }
        $slots = AppointmentSlot::query()
            ->where('doctor_profile_id', $profile->id)
            ->whereBetween('starts_at', [$rangeStart->utc(), $rangeEnd->utc()])
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
                        'filter_status' => match ($appointment->status) {
                            'completed' => 'confirmed',
                            'cancelled', 'rejected' => 'cancelled',
                            default => $appointment->status,
                        },
                        'can_confirm' => $appointment->status === 'booked',
                        'can_start' => $appointment->status === 'confirmed',
                    ];
                })->all();
            }

            if (! in_array($slot->status, ['available', 'blocked'], true)) {
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
                'filter_status' => $slot->status,
                'can_confirm' => false,
                'can_start' => false,
                'can_block' => $isFuture && $slot->status === 'available',
                'can_unblock' => $isFuture && $slot->status === 'blocked',
            ]];
        })->values();
        $activeFilters = $validated['filters'] ?? ['available', 'blocked', 'booked', 'confirmed', 'completed', 'cancelled', 'rejected'];
        $visibleCalendarEvents = $calendarEvents->filter(
            fn (array $event): bool => in_array($event['filter_status'], $activeFilters, true)
        )->values();
        $summary = [
            'total slots' => $selectedSlots->count(),
            'appointments' => $selectedAppointments->count(),
            'confirmed' => $selectedAppointments->where('status', 'confirmed')->count(),
            'pending' => $selectedAppointments->where('status', 'booked')->count(),
            'cancelled' => $selectedAppointments->whereIn('status', ['cancelled', 'rejected'])->count(),
            'available' => $selectedSlots->where('status', 'available')->count(),
            'blocked' => $selectedSlots->where('status', 'blocked')->count(),
        ];

        return view('doctor.schedule', [
            'profile' => $profile,
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
