<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `radiology:define-imaging-service define` named a `code` that already belongs
 * to an ACTIVE imaging service in the tenant (15-radiology-domain.md §12;
 * ADR-165 D-D — the CP.1 / Lab.1 catalog `code` tenant-unique-among-active
 * rule). A retired code may be reused. Maps to HTTP 409.
 */
final class ImagingServiceAlreadyExists extends DomainException
{
    public static function forCode(string $tenantId, string $code): self
    {
        return new self(sprintf(
            'An active imaging service with code [%s] already exists in tenant [%s].',
            $code,
            $tenantId,
        ));
    }

    public function errorCode(): string
    {
        return 'imaging_service_already_exists';
    }
}
