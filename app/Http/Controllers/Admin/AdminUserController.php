<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Models\User;
use App\Services\DoctorOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminUserController extends Controller
{
    public function __construct(private readonly DoctorOnboardingService $onboarding) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:patient,doctor,admin'],
        ]);
        $users = User::query()
            ->when($validated['search'] ?? null, fn ($query, $search) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($validated['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', compact('users'));
    }

    public function show(User $user): View
    {
        $user->loadMissing('doctorProfile');

        return view('admin.users.show', [
            'user' => $user,
            'doctorStatus' => $user->role === 'doctor' ? $this->onboarding->statusFor($user) : null,
            'statusLabels' => AdminDoctorController::STATUS_LABELS,
        ]);
    }

    public function update(UpdateAdminUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $targetRole = $data['role'];
        $deactivate = $request->boolean('deactivated');
        unset($data['role'], $data['deactivated']);

        if ($targetRole !== 'admin' && $user->role === 'admin'
            && User::where('role', 'admin')->where('id', '!=', $user->id)->whereNull('deactivated_at')->doesntExist()) {
            return back()->withErrors(['role' => 'At least one active administrator must remain.']);
        }

        if ($user->is($request->user()) && $targetRole !== 'admin') {
            return back()->withErrors(['role' => 'You cannot remove your own administrator access.']);
        }

        if ($deactivate && $user->is($request->user())) {
            return back()->withErrors(['deactivated' => 'You cannot deactivate your own account.']);
        }

        // Doctor accounts follow the onboarding workflow: they are created (and invited) from the Doctors page,
        // keep the doctor role, and are activated only by accepting their invitation.
        if ($user->role !== 'doctor' && $targetRole === 'doctor') {
            return back()->withErrors(['role' => 'Doctor accounts must be created from Doctors → Add doctor so they receive an invitation.']);
        }

        if ($user->role === 'doctor' && $targetRole !== 'doctor') {
            return back()->withErrors(['role' => 'A doctor account cannot change role. Deactivate it instead.']);
        }

        if ($user->role === 'doctor') {
            return $this->updateDoctor($user, $data, ! $deactivate);
        }

        $user->update($data);
        $user->role = $targetRole;
        $user->deactivated_at = $deactivate ? now() : null;
        $user->save();

        return back()->with('status', 'User account updated.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateDoctor(User $doctor, array $data, bool $active): RedirectResponse
    {
        $status = $this->onboarding->statusFor($doctor);

        if ($active && in_array($status, [DoctorOnboardingService::STATUS_INVITATION_PENDING, DoctorOnboardingService::STATUS_INVITATION_EXPIRED], true)) {
            return back()->withErrors(['deactivated' => 'This doctor has not accepted their invitation yet, so the account cannot be activated here. Resend the invitation from the Doctors page.']);
        }

        $invitation = null;

        DB::transaction(function () use ($doctor, $data, $active, &$invitation): void {
            $previousEmail = $doctor->email;
            $doctor->update($data);
            $this->onboarding->emailChanged($doctor, $previousEmail, $invitation);

            // Only an actual change of access goes through the onboarding rules; re-saving the form must not
            // revoke a pending invitation.
            if ($active !== ($doctor->deactivated_at === null)) {
                $this->onboarding->setAccess($doctor->fresh(), $active);
            }
        });

        return back()->with('status', 'User account updated.'.AdminDoctorController::invitationNotice($invitation));
    }
}
