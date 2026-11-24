<?php

namespace App\Http\Middleware;

use App\Services\DoctorWorkspace;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HtmlResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Moves pages opened through an older numbered URL to their current address. The page is produced first, so someone
 * who may not open it gets the usual refusal and never learns the address.
 */
class RedirectNumberedUrls
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $route = $request->route();

        if (! $request->isMethod('GET') || ! $response instanceof HtmlResponse || ! $response->isOk() || $route?->getName() === null) {
            return $response;
        }

        $parameters = $route->originalParameters();
        $changed = false;

        foreach ($parameters as $name => $value) {
            $model = $name === DoctorWorkspace::ROUTE_PARAMETER
                ? $request->attributes->get(DoctorWorkspace::REQUEST_ATTRIBUTE)
                : $route->parameter($name);

            if (ctype_digit((string) $value) && $model instanceof Model) {
                $parameters[$name] = $model->getRouteKey();
                $changed = true;
            }
        }

        if (! $changed) {
            return $response;
        }

        $request->session()->reflash();

        return redirect()->route($route->getName(), [...$request->query(), ...$parameters]);
    }
}
