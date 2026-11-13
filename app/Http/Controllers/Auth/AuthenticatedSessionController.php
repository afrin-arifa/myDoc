<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Failed attempts allowed for one email address from one IP address within the decay window.
     */
    private const MAX_ATTEMPTS_PER_ACCOUNT = 5;

    /**
     * Failed attempts allowed from one IP address across all email addresses within the decay window.
     */
    private const MAX_ATTEMPTS_PER_IP = 20;

    private const DECAY_SECONDS = 60;

    public function create(Request $request): View
    {
        $redirect = $request->string('redirect')->toString();

        if (Str::startsWith($redirect, '/') && ! Str::startsWith($redirect, '//')) {
            $request->session()->put('url.intended', $redirect);
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited($request);

        if (! Auth::attempt(array_merge($credentials, ['deactivated_at' => null]), $request->boolean('remember'))) {
            RateLimiter::hit($this->accountThrottleKey($request), self::DECAY_SECONDS);
            RateLimiter::hit($this->ipThrottleKey($request), self::DECAY_SECONDS);

            return back()
                ->withErrors(['email' => 'These credentials do not match our records.'])
                ->onlyInput('email');
        }

        RateLimiter::clear($this->accountThrottleKey($request));
        $request->session()->regenerate();

        return redirect()->intended(route($this->dashboardRoute(Auth::user()->role)));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'You have been logged out.');
    }

    /**
     * Reject the attempt when either the email-and-IP pair or the IP address alone has too many recent failures.
     * The same message is returned whether or not the account exists, so throttling does not reveal accounts.
     *
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(Request $request): void
    {
        $limits = [
            $this->accountThrottleKey($request) => self::MAX_ATTEMPTS_PER_ACCOUNT,
            $this->ipThrottleKey($request) => self::MAX_ATTEMPTS_PER_IP,
        ];

        foreach ($limits as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                event(new Lockout($request));

                throw ValidationException::withMessages([
                    'email' => trans('auth.throttle', [
                        'seconds' => RateLimiter::availableIn($key),
                        'minutes' => (int) ceil(RateLimiter::availableIn($key) / 60),
                    ]),
                ]);
            }
        }
    }

    private function accountThrottleKey(Request $request): string
    {
        return 'login:'.Str::transliterate(Str::lower($request->string('email')->toString()).'|'.$request->ip());
    }

    private function ipThrottleKey(Request $request): string
    {
        return 'login-ip:'.$request->ip();
    }

    private function dashboardRoute(string $role): string
    {
        return match ($role) {
            'doctor' => 'doctor.dashboard',
            'admin' => 'admin.dashboard',
            default => 'patient.dashboard',
        };
    }
}
