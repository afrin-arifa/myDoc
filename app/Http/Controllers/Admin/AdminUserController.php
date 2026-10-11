<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAdminUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminUserController extends Controller
{
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
        return view('admin.users.show', compact('user'));
    }

    public function update(UpdateAdminUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();
        $targetRole = $data['role'];
        unset($data['role'], $data['deactivated']);

        if ($targetRole !== 'admin' && $user->role === 'admin'
            && User::where('role', 'admin')->where('id', '!=', $user->id)->whereNull('deactivated_at')->doesntExist()) {
            return back()->withErrors(['role' => 'At least one active administrator must remain.']);
        }

        if ($user->is($request->user()) && $targetRole !== 'admin') {
            return back()->withErrors(['role' => 'You cannot remove your own administrator access.']);
        }

        if ($request->boolean('deactivated') && $user->is($request->user())) {
            return back()->withErrors(['deactivated' => 'You cannot deactivate your own account.']);
        }

        $user->update($data);
        $user->role = $targetRole;
        $user->deactivated_at = $request->boolean('deactivated') ? now() : null;
        $user->save();

        return back()->with('status', 'User account updated.');
    }
}
