<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminAppointmentController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:booked,confirmed,completed,cancelled,rejected'],
            'date' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $appointments = Appointment::query()
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->whereHas('slot', fn ($slots) => $slots->when(isset($validated['date']), fn ($q) => $q->whereDate('starts_at', $validated['date'])))
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where(fn ($q) => $q
                ->whereHas('patient', fn ($patient) => $patient->where('name', 'like', "%{$search}%"))
                ->orWhereHas('slot.doctorProfile.user', fn ($doctor) => $doctor->where('name', 'like', "%{$search}%"))))
            ->with(['patient:id,name,email', 'slot.doctorProfile.user:id,name'])
            ->join('appointment_slots', 'appointments.appointment_slot_id', '=', 'appointment_slots.id')
            ->orderByDesc('appointment_slots.starts_at')
            ->select('appointments.*')
            ->paginate(15)
            ->withQueryString();

        return view('admin.appointments.index', compact('appointments'));
    }

    public function show(Appointment $appointment): View
    {
        $appointment->load(['patient:id,name,email', 'slot.doctorProfile.user:id,name']);

        return view('admin.appointments.show', compact('appointment'));
    }
}
