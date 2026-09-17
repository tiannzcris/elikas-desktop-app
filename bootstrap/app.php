<?php

use App\Http\Middleware\EnsureDatabaseIsUpToDate;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // First thing that runs on every web request -- see
        // EnsureDatabaseIsUpToDate's own docblock for why this can't be
        // scoped to only authenticated/protected routes.
        $middleware->prependToGroup('web', EnsureDatabaseIsUpToDate::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
