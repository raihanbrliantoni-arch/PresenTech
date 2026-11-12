<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureDeviceKey;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // API-only: jangan pernah redirect tamu ke route bernama 'login'
        // (route itu tidak ada) — cukup tolak lewat exception 401 JSON.
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        // Header keamanan dipasang untuk semua respons, termasuk /app.
        $middleware->append(SecurityHeaders::class);

        // Tunnel Cloudflare berjalan di localhost, jadi hanya proxy lokal yang
        // dipercaya. Tanpa ini HSTS tidak terdeteksi dan rate limit memakai
        // IP proxy (semua pengguna terlihat satu IP). Header Host sengaja
        // tidak dipercaya dari proxy agar Host tidak bisa dipalsukan client.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Perangkat fisik harus dicek key-nya sebelum auth:sanctum, supaya
        // route /devices/{device}/heartbeat tidak bisa lolos hanya dengan
        // sesi login dan kuota throttle tidak habis oleh request tanpa key.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            EnsureDeviceKey::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            'active' => EnsureAccountIsActive::class,
            'password.changed' => EnsurePasswordChanged::class,
            'device.key' => EnsureDeviceKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Jaminan kedua: AuthenticationException selalu 401 JSON (semua format request).
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json(['message' => 'Belum terautentikasi.'], 401);
        });
    })->create();
