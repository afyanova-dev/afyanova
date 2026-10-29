<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use RuntimeException;

final class DuplicateSchedulableResourceException extends RuntimeException
{
    public static function forRef(string $resourceType, string $resourceRef, string $facilityId): self
    {
        return new self("Schedulable resource of type [{$resourceType}] with ref [{$resourceRef}] already registered in facility [{$facilityId}].");
    }
}
