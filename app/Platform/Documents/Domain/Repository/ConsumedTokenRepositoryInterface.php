<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Repository;

use Carbon\CarbonImmutable;

interface ConsumedTokenRepositoryInterface
{
    /**
     * Atomically records the consumption of a capability token.
     * Returns true if successfully recorded, false if token was already consumed.
     */
    public function recordConsumption(
        string $tenantId,
        string $jti,
        CarbonImmutable $consumedAt,
        CarbonImmutable $expiresAt,
    ): bool;

    public function isConsumed(string $tenantId, string $jti): bool;
}
