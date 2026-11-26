<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use App\Models\DoctorWorkspaceEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A doctor's read-only view of the assistants who work for them and what those assistants did in their workspace.
 * Assignments stay with administrators. Only this doctor's own workspace events are ever queried.
 */
class DoctorAssistantController extends Controller
{
    public function index(Request $request): View
    {
        $profile = $request->user()->doctorProfile;
        abort_unless($profile, 403);

        $assistantKey = $request->validate(['assistant' => ['nullable', 'string', 'max:20']])['assistant'] ?? null;
        $assistantId = $assistantKey !== null ? User::idFromUrlKey($assistantKey) ?? 0 : null;
        $assistantEvents = fn () => DoctorWorkspaceEvent::query()
            ->where('doctor_profile_id', $profile->id)
            ->where('actor_role', User::ROLE_ASSISTANT);

        return view('doctor.assistants.index', [
            'profile' => $profile,
            'assignments' => $profile->assistantAssignments()
                ->with('assistant:id,name,email,phone,photo_path,deactivated_at')
                ->get()
                ->sortBy('assistant.name')
                ->values(),
            // Former assistants stay selectable, because their earlier actions remain on record.
            'actors' => User::query()->whereIn('id', $assistantEvents()->select('actor_id'))->orderBy('name')->get(['id', 'name']),
            'assistantId' => $assistantId,
            'events' => $assistantEvents()
                ->when($assistantId !== null, fn ($events) => $events->where('actor_id', $assistantId))
                ->with(['actor:id,name', 'appointment.patient:id,name', 'appointment.slot'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    /**
     * One of this doctor's current assistants: contact details, what they may do and what they last did here. Any
     * other account, including a former assistant of this doctor, is not found.
     */
    public function show(Request $request, User $assistant): View
    {
        $profile = $request->user()->doctorProfile;
        abort_unless($profile, 403);

        $assignment = $profile->assistantAssignments()->where('assistant_id', $assistant->id)->first();
        abort_if($assignment === null, 404);

        $events = DoctorWorkspaceEvent::query()
            ->where('doctor_profile_id', $profile->id)
            ->where('actor_role', User::ROLE_ASSISTANT)
            ->where('actor_id', $assistant->id);

        return view('doctor.assistants.show', [
            'assistant' => $assistant,
            'assignment' => $assignment,
            'activityCount' => (clone $events)->count(),
            'lastActiveAt' => (clone $events)->max('created_at'),
            'recentEvents' => $events
                ->with(['appointment.patient:id,name', 'appointment.slot'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(8)
                ->get(),
        ]);
    }
}
