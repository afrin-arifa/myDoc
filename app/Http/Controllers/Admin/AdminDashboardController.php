<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(): View
    {
        $users = User::query()->whereIn('role', ['patient', 'doctor'])->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role');
        $byStatus = Appointment::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $counts = [
            'patients' => (int) ($users['patient'] ?? 0),
            'doctors' => (int) ($users['doctor'] ?? 0),
            'appointments' => (int) $byStatus->sum(),
            'upcoming' => Appointment::query()->upcoming()->count(),
            'booked' => (int) ($byStatus['booked'] ?? 0),
            'confirmed' => (int) ($byStatus['confirmed'] ?? 0),
            'completed' => (int) ($byStatus['completed'] ?? 0),
            'cancelled_or_rejected' => (int) $byStatus->only(Appointment::CLOSED_STATUSES)->sum(),
        ];

        return view('admin.dashboard', compact('counts'));
    }
}
