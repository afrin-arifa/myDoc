<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Specialty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminSpecialtyController extends Controller
{
    public function index(): View
    {
        return view('admin.specialties.index', ['specialties' => Specialty::withCount('doctorProfiles')->orderBy('name')->paginate(15)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:specialties,name'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        Specialty::create($data);

        return back()->with('status', 'Specialty created.');
    }

    public function update(Request $request, Specialty $specialty): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('specialties', 'name')->ignore($specialty)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $specialty->update($data);

        return back()->with('status', 'Specialty updated.');
    }

    public function destroy(Specialty $specialty): RedirectResponse
    {
        if ($specialty->doctorProfiles()->exists()) {
            return back()->withErrors(['specialty' => 'Referenced specialties cannot be deleted. Deactivate it instead.']);
        }

        $specialty->delete();

        return back()->with('status', 'Specialty deleted.');
    }
}
