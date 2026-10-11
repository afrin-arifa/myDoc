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
        $doctors = DoctorProfile::query()
            ->where('is_accepting_patients', true)
            ->with('specialties:id,name')
            ->when($request->filled('specialty'), function ($query) use ($request): void {
                $query->whereHas('specialties', fn ($specialties) => $specialties->whereKey($request->integer('specialty')));
            })
            ->orderBy('professional_title')
            ->orderBy('id')
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
