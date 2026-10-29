<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\ClaimSummary;
use App\Domains\Insurance\Domain\ClaimReader;

/**
 * Orchestrates `GetPayerClaims` (ADR-204).
 */
final class GetPayerClaimsHandler
{
    public function __construct(private readonly ClaimReader $reader) {}

    /**
     * @return list<ClaimSummary>
     */
    public function __invoke(GetPayerClaims $query): array
    {
        return $this->reader->getPayerClaims($query->payerId, $query->status);
    }
}
