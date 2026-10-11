<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterUserRequest $request): RedirectResponse
    {
        $user = User::create($request->validated());
        $user->role = 'patient';
        $user->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('patient.dashboard')
            ->with('status', 'Your patient account has been created.');
    }
}
