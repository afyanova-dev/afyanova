<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Illuminate\Http\JsonResponse;

/**
 * Envelope for the machine-facing API surface (/api/v1/*, IMPLEMENTATION-CONTRACTS.md §15).
 * Human/Inertia responses do NOT use this.
 */
final class ApiResponse
{
    /** @param  array<string, mixed>|list<mixed>  $data */
    public static function ok(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }

    public static function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
