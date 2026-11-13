<?php

namespace App\Http\Controllers;

use App\Models\DoctorProfile;
use App\Models\Specialty;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('home', [
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
