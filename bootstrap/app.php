<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureWorksForDoctor;
use App\Http\Middleware\RedirectNumberedUrls;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'works-for-doctor' => EnsureWorksForDoctor::class,
        ]);

        // The assistant workspace guard needs the route-bound {doctorProfile}, so it runs after route model binding.
        $middleware->appendToPriorityList(SubstituteBindings::class, EnsureWorksForDoctor::class);

        $middleware->web(append: [RedirectNumberedUrls::class]);

        // Sign out every other session (and remember-me cookie) of a user whose password changes, e.g. after a
        // password reset. The session that made the change stays signed in.
        $middleware->authenticateSessions();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
