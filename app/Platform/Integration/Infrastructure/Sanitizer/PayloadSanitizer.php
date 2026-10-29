<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Sanitizer;

use App\Platform\Integration\Contracts\Port\PayloadSanitizerPort;

final class PayloadSanitizer implements PayloadSanitizerPort
{
    /** @var list<string> */
    private const SENSITIVE_PATTERNS = [
        'password',
        'secret',
        'token',
        'pin',
        'cvv',
        'api_key',
        'apikey',
        'private_key',
        'authorization',
        'auth_key',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        return $this->sanitizeRecursive($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeRecursive(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitizeRecursive($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lowerKey = strtolower($key);

        foreach (self::SENSITIVE_PATTERNS as $pattern) {
            if (str_contains($lowerKey, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
