<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\ValueObject;

use App\Platform\Documents\Domain\Exception\TenantIsolationViolationException;
use InvalidArgumentException;

final readonly class StorageKey
{
    private const array MIME_MAP = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/svg+xml' => 'svg',
        'application/json' => 'json',
        'text/plain' => 'txt',
        'text/html' => 'html',
        'application/xml' => 'xml',
        'text/xml' => 'xml',
    ];

    public function __construct(
        public string $value
    ) {
        if (str_contains($this->value, '..') || str_starts_with($this->value, '/')) {
            throw new InvalidArgumentException("Path traversal detected in storage key: {$this->value}");
        }
    }

    public static function generate(
        string $tenantId,
        string $documentId,
        int $versionNo,
        string $contentHash,
        string $mimeType
    ): self {
        return self::forVersion($tenantId, $documentId, $versionNo, $contentHash, $mimeType);
    }

    public static function forVersion(
        string $tenantId,
        string $documentId,
        int $versionNo,
        string $contentHash,
        string $extension = 'bin'
    ): self {
        $ext = self::MIME_MAP[strtolower($extension)] ?? $extension;
        $ext = ltrim($ext, '.');
        $path = sprintf(
            'tenants/%s/documents/%s/%03d_%s.%s',
            $tenantId,
            $documentId,
            $versionNo,
            $contentHash,
            $ext
        );

        return new self($path);
    }

    public function assertBelongsToTenant(string $tenantId): void
    {
        $expectedPrefix = "tenants/{$tenantId}/";
        if (! str_starts_with($this->value, $expectedPrefix)) {
            throw new TenantIsolationViolationException(
                "Cross-tenant storage access violation. Key '{$this->value}' does not match active tenant '{$tenantId}'."
            );
        }
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
