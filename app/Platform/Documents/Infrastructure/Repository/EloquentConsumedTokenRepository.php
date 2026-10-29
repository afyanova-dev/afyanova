<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Repository;

use App\Platform\Documents\Domain\Model\ConsumedToken;
use App\Platform\Documents\Domain\Repository\ConsumedTokenRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

final class EloquentConsumedTokenRepository implements ConsumedTokenRepositoryInterface
{
    public function recordConsumption(
        string $tenantId,
        string $jti,
        CarbonImmutable $consumedAt,
        CarbonImmutable $expiresAt,
    ): bool {
        try {
            ConsumedToken::query()->insert([
                'tenant_id' => $tenantId,
                'jti' => $jti,
                'consumed_at' => $consumedAt,
                'expires_at' => $expiresAt,
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    public function isConsumed(string $tenantId, string $jti): bool
    {
        return ConsumedToken::query()
            ->where('tenant_id', $tenantId)
            ->where('jti', $jti)
            ->exists();
    }
}
