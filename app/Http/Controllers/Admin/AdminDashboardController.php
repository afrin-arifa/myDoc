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
        $counts = [
            'patients' => User::where('role', 'patient')->count(),
            'doctors' => User::where('role', 'doctor')->count(),
            'appointments' => Appointment::count(),
            'upcoming' => Appointment::whereHas('slot', fn ($slots) => $slots->where('starts_at', '>=', now()))->count(),
            'booked' => Appointment::where('status', 'booked')->count(),
            'confirmed' => Appointment::where('status', 'confirmed')->count(),
            'completed' => Appointment::where('status', 'completed')->count(),
            'cancelled_or_rejected' => Appointment::whereIn('status', ['cancelled', 'rejected'])->count(),
        ];

        return view('admin.dashboard', compact('counts'));
    }
}
