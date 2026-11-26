<?php

namespace App\Http\Controllers;

use App\Models\DoctorAnnouncement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PatientAnnouncementController extends Controller
{
    /**
     * Every current notice from the patient's doctors. Ones the patient has not dismissed yet are marked new.
     */
    public function index(Request $request): View
    {
        $patient = $request->user();

        return view('patient.announcements.index', [
            'announcements' => DoctorAnnouncement::query()
                ->forPatient($patient)
                ->with('doctorProfile.user:id,name', 'doctorProfile.specialties:id,name')
                ->withExists(['dismissedBy as is_read' => fn (Builder $users) => $users->whereKey($patient->id)])
                ->paginate(10),
        ]);
    }

    /**
     * Hide an announcement for this patient. Only an announcement the patient can currently see can be dismissed.
     */
    public function dismiss(Request $request, DoctorAnnouncement $announcement): RedirectResponse
    {
        $patient = $request->user();
        abort_unless(DoctorAnnouncement::query()->visibleToPatient($patient)->whereKey($announcement->id)->exists(), 404);

        $patient->dismissedAnnouncements()->syncWithoutDetaching([$announcement->id => ['created_at' => now()]]);

        return back()->with('status', $request->boolean('from_list') ? 'Marked as read.' : null);
    }
}
