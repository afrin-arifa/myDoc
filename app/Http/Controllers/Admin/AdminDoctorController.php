<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminDoctorRequest;
use App\Models\DoctorProfile;
use App\Models\Specialty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminDoctorController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? null;
        $doctors = DoctorProfile::query()
            ->with(['user:id,name,email', 'specialties:id,name'])
            ->when($search, fn ($query) => $query->where(fn ($q) => $q
                ->where('professional_title', 'like', "%{$search}%")
                ->orWhere('license_number', 'like', "%{$search}%")
                ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$search}%"))))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.doctors.index', compact('doctors'));
    }

    public function show(DoctorProfile $doctor): View
    {
        $doctor->load(['user', 'specialties']);

        return view('admin.doctors.show', [
            'doctor' => $doctor,
            'specialties' => Specialty::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateAdminDoctorRequest $request, DoctorProfile $doctor): RedirectResponse
    {
        $data = $request->validated();
        $specialtyIds = $data['specialty_ids'] ?? [];
        unset($data['specialty_ids']);
        $data['is_accepting_patients'] = $request->boolean('is_accepting_patients');
        $userData = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'address']));
        $profileData = array_diff_key($data, $userData);

        DB::transaction(function () use ($doctor, $userData, $profileData, $specialtyIds): void {
            $doctor->user->update($userData);
            $doctor->update($profileData);
            $doctor->specialties()->sync($specialtyIds);
        });

        return back()->with('status', 'Doctor profile updated.');
    }
}
