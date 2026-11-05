<?php

use App\Http\Middleware\AddQueryCountHeader;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureWarehouseAccess;
use App\Http\Middleware\LabAccess;
use App\Http\Middleware\LogSlowRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Short names for route definitions: ->middleware('role:manager,admin').
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'warehouse.access' => EnsureWarehouseAccess::class,
            'lab' => LabAccess::class,
        ]);

        // Added to the end of the "web" group: runs for every page, after the
        // session, CSRF and route model binding middleware of that group.
        $middleware->web(append: [
            LogSlowRequests::class,
            AddQueryCountHeader::class,
        ]);

        // Defaults, spelled out: guests go to the login form ("auth"),
        // logged-in users leave login/register ("guest").
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
