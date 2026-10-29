<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * Facility does not have the required capability enabled for ward creation
 * (24-bed-and-ward-management-domain.md §7 Inv 7, §12; ADR-148 D-E). Maps to HTTP 409.
 */
final class WardCapabilityUnavailable extends DomainException
{
    public static function forWard(string $facilityId, string $capabilityCode): self
    {
        return new self(sprintf(
            'Facility [%s] does not have capability [%s] enabled — this ward cannot be created there.',
            $facilityId,
            $capabilityCode,
        ));
    }

    public function errorCode(): string
    {
        return 'ward_capability_unavailable';
    }
}
