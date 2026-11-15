<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The root URL. myDoc has no public homepage: guests are sent to the login page by the auth middleware, and a
 * signed-in user is sent to their role's dashboard (the same mapping used after login).
 */
class HomeController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $dashboard = AuthenticatedSessionController::dashboardRoute($request->user()->role);

        abort_if($dashboard === null, 403);

        return redirect()->route($dashboard);
    }
}
