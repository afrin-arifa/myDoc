<?php

namespace App\Http\Middleware;

use App\Models\DoctorProfile;
use App\Services\DoctorAccess;
use App\Services\DoctorWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every assistant workspace route: the route-bound {doctorProfile} must be an active doctor the signed-in
 * user currently works for, checked in the database on every request (DoctorAccess). Each controller then checks
 * the specific ability for its action. Anything else is refused before a controller runs.
 *
 * The authorized profile is handed to DoctorWorkspace as a request attribute and removed from the route parameters,
 * so the reused doctor controllers receive exactly the parameters they declare.
 */
class EnsureWorksForDoctor
{
    public function __construct(private readonly DoctorAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $profile = $request->route(DoctorWorkspace::ROUTE_PARAMETER);

        abort_unless(
            $profile instanceof DoctorProfile && $request->user() !== null && $this->access->worksFor($request->user(), $profile),
            403
        );

        $request->attributes->set(DoctorWorkspace::REQUEST_ATTRIBUTE, $profile);
        $request->route()->forgetParameter(DoctorWorkspace::ROUTE_PARAMETER);

        return $next($request);
    }
}
