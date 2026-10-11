<?php

namespace App\Http\Controllers;

use App\Models\DoctorProfile;
use App\Models\Specialty;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DoctorDirectoryController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'specialty' => ['nullable', 'integer', 'exists:specialties,id'],
            'filter' => ['nullable', 'in:all,today,fee,experience'],
            'sort' => ['nullable', 'in:name,fee,experience'],
        ]);
        $filter = $validated['filter'] ?? $validated['sort'] ?? 'all';

        $doctors = DoctorProfile::query()
            ->where('is_accepting_patients', true)
            ->with([
                'user:id,name',
                'specialties:id,name',
                'appointmentSlots' => fn ($slots) => $slots
                    ->where('status', 'available')
                    ->where('starts_at', '>', now())
                    ->orderBy('starts_at'),
            ])
            ->when($validated['search'] ?? null, function ($query, $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->whereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"))
                        ->orWhere('professional_title', 'like', "%{$search}%")
                        ->orWhereHas('specialties', fn ($specialties) => $specialties->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($validated['specialty'] ?? null, function ($query, $specialty): void {
                $query->whereHas('specialties', fn ($specialties) => $specialties->whereKey($specialty));
            })
            ->when($filter === 'today', fn ($query) => $query->whereHas('appointmentSlots', fn ($slots) => $slots
                ->where('status', 'available')
                ->whereDate('starts_at', today())))
            ->when($filter === 'fee', fn ($query) => $query->orderBy('consultation_fee')->orderBy('id'))
            ->when($filter === 'experience', fn ($query) => $query->orderByDesc('years_of_experience')->orderBy('id'))
            ->when($filter === 'all', fn ($query) => $query
                ->join('users', 'doctor_profiles.user_id', '=', 'users.id')
                ->orderBy('users.name')
                ->select('doctor_profiles.*')
                ->orderBy('doctor_profiles.id'))
            ->paginate(12)
            ->withQueryString();

        return view('doctors.index', [
            'doctors' => $doctors,
            'specialties' => Specialty::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function show(DoctorProfile $doctor): View
    {
        abort_unless($doctor->is_accepting_patients, 404);

        $doctor->load([
            'user:id,name',
            'specialties:id,name',
            'appointmentSlots' => fn ($slots) => $slots
                ->where('status', 'available')
                ->where('starts_at', '>', now())
                ->orderBy('starts_at'),
        ]);

        return view('doctors.show', compact('doctor'));
    }
}
