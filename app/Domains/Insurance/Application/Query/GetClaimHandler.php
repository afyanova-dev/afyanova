<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\ClaimSummary;
use App\Domains\Insurance\Domain\ClaimReader;
use App\Domains\Insurance\Domain\Exception\ClaimNotFound;

/**
 * Orchestrates `GetClaim` (ADR-204).
 */
final class GetClaimHandler
{
    public function __construct(private readonly ClaimReader $reader) {}

    public function __invoke(GetClaim $query): ClaimSummary
    {
        return $this->reader->getById($query->claimId)
            ?? throw ClaimNotFound::id($query->claimId);
    }
}
