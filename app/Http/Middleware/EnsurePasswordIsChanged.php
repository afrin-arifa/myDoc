<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user signed in with a one-time password from the clinic may only choose their own password or sign out. Once
 * the one-time password expires, the session ends.
 */
class EnsurePasswordIsChanged
{
    /**
     * Routes that stay open while the password still has to be changed.
     */
    private const ALLOWED_ROUTES = ['password.first', 'password.first.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->mustChangePassword() || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($user->temporaryPasswordExpired()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your one-time password has expired. Ask your clinic for a new one, or use "Forgot password".']);
        }

        abort_if($request->expectsJson(), 403, 'Choose your own password first.');

        return redirect()->route('password.first');
    }
}
