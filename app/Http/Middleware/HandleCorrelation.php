<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class HandleCorrelation
{
    /**
     * Handle an incoming request.
     *
     * Reads the X-Correlation-ID header (reuses it if a valid UUID), or generates
     * a fresh UUID. Stores the ID and basic request context via the Context facade
     * so it auto-propagates to all log channels and dispatched queued jobs.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incomingId = $request->header('X-Correlation-ID');
        $correlationId = ($incomingId && Str::isUuid($incomingId))
            ? $incomingId
            : (string) Str::uuid();

        Context::add('correlation_id', $correlationId);
        Context::add('user_id', $request->user()?->id);
        Context::add('url', $request->method().' '.$request->path());

        $response = $next($request);

        $response->headers->set('X-Correlation-ID', $correlationId);

        return $response;
    }
}
