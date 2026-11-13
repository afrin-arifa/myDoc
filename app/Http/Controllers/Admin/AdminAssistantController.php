<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminAssistantRequest;
use App\Http\Requests\Admin\StoreAssistantAssignmentsRequest;
use App\Http\Requests\Admin\UpdateAdminAssistantRequest;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentEvent;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Services\AccountInvitations;
use App\Services\AssistantAssignmentService;
use App\Services\AssistantOnboardingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin management of Doctor Assistant accounts and their doctor assignments. Every action is admin-only (route
 * middleware, AssistantAssignmentPolicy, and the services re-check the actor). Pages never show password hashes or
 * invitation tokens, and offer no way to change an account's role.
 */
class AdminAssistantController extends Controller
{
    public function __construct(
        private readonly AssistantOnboardingService $onboarding,
        private readonly AssistantAssignmentService $assignments,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('manage', AssistantAssignment::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(AccountInvitations::STATUS_LABELS))],
        ]);

        $assistants = User::query()
            ->where('role', User::ROLE_ASSISTANT)
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query->where(fn (Builder $matches) => $matches
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $this->filterByStatus($query, $status))
            ->select(['id', 'name', 'email', 'deactivated_at', 'email_verified_at', 'created_at'])
            ->withCount('assistantAssignments')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.assistants.index', [
            'assistants' => $assistants,
            'statuses' => $this->onboarding->statusesFor($assistants->getCollection()),
            'statusLabels' => AccountInvitations::STATUS_LABELS,
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('manage', AssistantAssignment::class);

        return view('admin.assistants.create');
    }

    public function store(StoreAdminAssistantRequest $request): RedirectResponse
    {
        ['assistant' => $assistant, 'invitation' => $invitation] = $this->onboarding->createAssistant($request->user(), $request->validated());

        $redirect = redirect()->route('admin.assistants.show', $assistant);

        return $invitation === AssistantOnboardingService::INVITATION_SENT
            ? $redirect->with('status', 'Assistant account created. An invitation has been emailed to '.$assistant->email.'.')
            : $redirect->with('status', 'Assistant account created, but the invitation email could not be sent. Use "Resend invitation" to try again.');
    }

    public function show(User $assistant): View
    {
        Gate::authorize('manage', AssistantAssignment::class);

        $status = $this->onboarding->statusFor($assistant);
        $assignedIds = $assistant->assistantAssignments()->pluck('doctor_profile_id');

        return view('admin.assistants.show', [
            'assistant' => $assistant,
            'status' => $status,
            'statusLabels' => AccountInvitations::STATUS_LABELS,
            'invitationExpiresAt' => $this->onboarding->invitationExpiresAt($assistant),
            'canResendInvitation' => $this->onboarding->awaitsInvitation($assistant),
            'assignments' => $assistant->assistantAssignments()
                ->with(['doctorProfile.user:id,name,email,deactivated_at', 'assignedBy:id,name'])
                ->orderBy('created_at')
                ->get(),
            'availableDoctors' => $status === AccountInvitations::STATUS_ACTIVE
                ? DoctorProfile::query()
                    ->whereHas('user', fn (Builder $doctors) => $doctors->where('role', 'doctor')->whereNull('deactivated_at'))
                    ->whereNotIn('id', $assignedIds)
                    ->with('user:id,name,email')
                    ->get()
                    ->sortBy('user.name')
                    ->values()
                : collect(),
            'history' => AssistantAssignmentEvent::query()
                ->where('assistant_id', $assistant->id)
                ->with(['doctorProfile.user:id,name', 'actor:id,name'])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(15, ['*'], 'history_page'),
        ]);
    }

    public function edit(User $assistant): View
    {
        Gate::authorize('manage', AssistantAssignment::class);

        return view('admin.assistants.edit', ['assistant' => $assistant]);
    }

    public function update(UpdateAdminAssistantRequest $request, User $assistant): RedirectResponse
    {
        $invitation = $this->onboarding->updateDetails($request->user(), $assistant, $request->validated());

        $redirect = redirect()->route('admin.assistants.show', $assistant);

        return match ($invitation) {
            AssistantOnboardingService::INVITATION_SENT => $redirect->with('status', 'Assistant details updated. A new invitation has been emailed to the new address; the old link no longer works.'),
            AssistantOnboardingService::INVITATION_FAILED => $redirect->with('status', 'Assistant details updated, but the new invitation email could not be sent. Use "Resend invitation" to try again.'),
            default => $redirect->with('status', 'Assistant details updated.'),
        };
    }

    public function updateStatus(Request $request, User $assistant): RedirectResponse
    {
        Gate::authorize('manage', AssistantAssignment::class);
        $action = $request->validate(['action' => ['required', 'in:activate,deactivate']])['action'];

        if ($action === 'deactivate') {
            $removed = $this->onboarding->deactivate($request->user(), $assistant);

            return back()->with('status', "Assistant account deactivated. {$removed} doctor assignment(s) removed; the assignment history is kept.");
        }

        if (! $this->onboarding->activate($request->user(), $assistant)) {
            return back()->withErrors(['status' => 'This assistant has not set a password yet. Send a new invitation instead.']);
        }

        return back()->with('status', 'Assistant account reactivated. Earlier doctor assignments are not restored; assign doctors again if needed.');
    }

    public function resendInvitation(Request $request, User $assistant): RedirectResponse
    {
        Gate::authorize('manage', AssistantAssignment::class);

        return match ($this->onboarding->resendInvitation($request->user(), $assistant)) {
            AssistantOnboardingService::INVITATION_SENT => back()->with('status', 'A new invitation has been emailed. Earlier invitation links no longer work.'),
            AssistantOnboardingService::INVITATION_THROTTLED => back()->withErrors(['invitation' => 'An invitation was sent moments ago. Please wait a minute before sending another.']),
            AssistantOnboardingService::INVITATION_FAILED => back()->withErrors(['invitation' => 'A new invitation was created, but the email could not be sent. Please try again shortly.']),
            default => back()->withErrors(['invitation' => 'Invitations can only be sent to assistants who have not set a password yet.']),
        };
    }

    public function storeAssignments(StoreAssistantAssignmentsRequest $request, User $assistant): RedirectResponse
    {
        $count = $this->assignments->assign($request->user(), $assistant, $request->doctorProfileIds());

        return back()->with('status', "{$count} doctor(s) assigned.");
    }

    public function destroyAssignment(Request $request, User $assistant, DoctorProfile $doctor): RedirectResponse
    {
        Gate::authorize('manage', AssistantAssignment::class);
        $this->assignments->unassign($request->user(), $assistant, $doctor);

        return back()->with('status', 'Assignment removed. The assistant no longer has access to this doctor\'s workspace.');
    }

    /**
     * @param  Builder<User>  $query
     */
    private function filterByStatus(Builder $query, string $status): void
    {
        $cutoff = now()->subMinutes($this->onboarding->invitations()->expiryMinutes());
        $invitation = fn ($tokens) => $tokens->from(AssistantOnboardingService::TOKEN_TABLE)
            ->whereColumn(AssistantOnboardingService::TOKEN_TABLE.'.email', 'users.email');

        match ($status) {
            AccountInvitations::STATUS_ACTIVE => $query->whereNull('deactivated_at'),
            AccountInvitations::STATUS_INVITATION_PENDING => $query->whereNotNull('deactivated_at')
                ->whereExists(fn ($tokens) => $invitation($tokens)->where('created_at', '>', $cutoff)),
            AccountInvitations::STATUS_INVITATION_EXPIRED => $query->whereNotNull('deactivated_at')
                ->whereExists(fn ($tokens) => $invitation($tokens)->where('created_at', '<=', $cutoff)),
            default => $query->whereNotNull('deactivated_at')->whereNotExists($invitation),
        };
    }
}
