<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the request correlation id and binds it to the log context
 * (IMPLEMENTATION-CONTRACTS.md §19). Never logs PHI.
 */
final class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get('X-Correlation-Id');
        $correlationId = is_string($incoming) && Str::isUuid($incoming)
            ? $incoming
            : (string) Str::uuid7();

        CorrelationId::set($correlationId);

        Log::withContext([
            'correlation_id' => $correlationId,
            'domain' => 'http',
        ]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }

    public function terminate(): void
    {
        CorrelationId::reset();
        Causation::reset();
    }
}
