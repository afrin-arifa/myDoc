<?php

namespace App\Http\Controllers;

use App\Models\DoctorProfile;
use App\Models\Specialty;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        // Doctors cannot browse other doctors, so the directory sections are neither queried nor shown for them.
        if (Gate::denies('browseDirectory', DoctorProfile::class)) {
            return view('home', [
                'canBrowseDoctors' => false,
                'featuredDoctors' => collect(),
                'featuredSpecialties' => collect(),
            ]);
        }

        return view('home', [
            'canBrowseDoctors' => true,
            'featuredDoctors' => DoctorProfile::query()
                ->publiclyListed()
                ->with(['user:id,name', 'specialties:id,name'])
                ->orderByDesc('years_of_experience')
                ->orderBy('id')
                ->limit(4)
                ->get(),
            'featuredSpecialties' => Specialty::query()
                ->where('is_active', true)
                ->withCount(['doctorProfiles' => fn ($doctors) => $doctors->publiclyListed()])
                ->orderBy('name')
                ->limit(6)
                ->get(),
        ]);
    }
}
