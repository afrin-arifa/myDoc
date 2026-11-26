<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Doctor\StoreAnnouncementRequest;
use App\Models\DoctorAnnouncement;
use App\Models\DoctorWorkspaceEvent;
use App\Models\MedicalDocument;
use App\Models\User;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use App\Services\DoctorWorkspaceEventService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Announcements a doctor or their assistant posts for that doctor's own patients. Only the selected doctor's
 * announcements are ever listed or removed.
 */
class DoctorAnnouncementController extends Controller
{
    public function __construct(private readonly DoctorWorkspaceEventService $events) {}

    public function index(Request $request, DoctorWorkspace $workspace): View
    {
        $profile = $workspace->forRequest($request, DoctorAccess::MANAGE_ANNOUNCEMENTS)?->load('user');
        abort_unless($profile, 403);

        $unreadIds = DoctorAnnouncement::query()->unreadForStaff($request->user(), [$profile->id])->pluck('id')->all();
        $announcements = DoctorAnnouncement::query()
            ->where('doctor_profile_id', $profile->id)
            ->with('author:id,name,role')
            ->withCount(['dismissedBy' => fn (Builder $users) => $users->where('role', 'patient')])
            ->latest()
            ->latest('id')
            ->paginate(10);

        // Opening the page counts as reading colleagues' new announcements; they are still marked new this time.
        $request->user()->dismissedAnnouncements()->syncWithoutDetaching(array_fill_keys($unreadIds, ['created_at' => now()]));

        return view('doctor.announcements.index', [
            'profile' => $profile,
            'announcements' => $announcements,
            'unreadIds' => $unreadIds,
            'audienceCount' => User::query()
                ->activePatients()
                ->where(fn (Builder $patients) => $patients
                    ->where('registered_by_doctor_profile_id', $profile->id)
                    ->orWhereHas('appointments', fn (Builder $appointments) => $appointments
                        ->whereIn('status', MedicalDocument::CARE_STATUSES)
                        ->whereHas('slot', fn (Builder $slots) => $slots->where('doctor_profile_id', $profile->id))))
                ->count(),
        ]);
    }

    public function store(StoreAnnouncementRequest $request, DoctorWorkspace $workspace): RedirectResponse
    {
        $profile = $workspace->forRequest($request, DoctorAccess::MANAGE_ANNOUNCEMENTS);
        abort_unless($profile, 403);

        DB::transaction(function () use ($request, $profile): void {
            $announcement = new DoctorAnnouncement;
            $announcement->forceFill([
                'doctor_profile_id' => $profile->id,
                'author_id' => $request->user()->id,
                'title' => $request->validated('title'),
                'body' => $request->validated('body'),
                'is_important' => $request->boolean('is_important'),
                'expires_at' => $request->validated('expires_on')
                    ? CarbonImmutable::parse($request->validated('expires_on'), config('app.timezone'))->endOfDay()
                    : null,
            ])->save();

            $this->events->record($request->user(), $profile, DoctorWorkspaceEvent::ACTION_ANNOUNCEMENT_POSTED, context: [
                'announcement_title' => $announcement->title,
            ]);
        });

        return redirect($workspace->url('doctor.announcements.index'))->with('status', 'Your announcement is now visible to your patients.');
    }

    /**
     * A doctor or assistant marks a colleague's announcement as read, for example from their dashboard.
     */
    public function markRead(Request $request, DoctorAccess $access, DoctorAnnouncement $announcement): RedirectResponse
    {
        $user = $request->user();
        abort_unless(DoctorAnnouncement::query()
            ->unreadForStaff($user, $access->profileIdsFor($user, DoctorAccess::MANAGE_ANNOUNCEMENTS))
            ->whereKey($announcement->id)
            ->exists(), 404);

        $user->dismissedAnnouncements()->syncWithoutDetaching([$announcement->id => ['created_at' => now()]]);

        return back();
    }

    public function destroy(Request $request, DoctorWorkspace $workspace, DoctorAnnouncement $announcement): RedirectResponse
    {
        $profile = $workspace->forRequest($request, DoctorAccess::MANAGE_ANNOUNCEMENTS);
        abort_unless($profile && (int) $announcement->doctor_profile_id === (int) $profile->id, 404);

        DB::transaction(function () use ($request, $profile, $announcement): void {
            $this->events->record($request->user(), $profile, DoctorWorkspaceEvent::ACTION_ANNOUNCEMENT_REMOVED, context: [
                'announcement_title' => $announcement->title,
            ]);
            $announcement->delete();
        });

        return redirect($workspace->url('doctor.announcements.index'))->with('status', 'The announcement was removed.');
    }
}
