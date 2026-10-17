<?php

use App\Exceptions\BoilerplateException;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleCorrelation;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleCorrelation::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            HandleCorrelation::class,
        ]);

        $middleware->alias([
            'ensure-two-factor' => EnsureTwoFactorEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception): void {
            Log::error($exception->getMessage(), [
                'correlation_id' => Context::get('correlation_id'),
                'exception' => $exception::class,
            ]);
        });

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            // BoilerplateException renders itself — early return preserves the render() output
            if ($exception instanceof BoilerplateException) {
                return $response;
            }

            if ($request->expectsJson()) {
                return $response;
            }

            if (! app()->environment(['local', 'testing']) && in_array($response->getStatusCode(), [403, 404, 500, 503], true)) {
                return Inertia::render('error-page', ['status' => $response->getStatusCode()])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }

            if ($response->getStatusCode() === 419) {
                return back()->with([
                    'message' => 'La página expiró, por favor intenta de nuevo.',
                ]);
            }

            return $response;
        });
    })->create();
