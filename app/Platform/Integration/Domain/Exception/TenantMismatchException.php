<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Exception;

use RuntimeException;

final class TenantMismatchException extends RuntimeException
{
    public static function forConnector(string $expectedTenantId, string $actualTenantId): self
    {
        return new self("Tenant mismatch: connector is bound to tenant '{$expectedTenantId}', but operation requested tenant '{$actualTenantId}'.");
    }
}
