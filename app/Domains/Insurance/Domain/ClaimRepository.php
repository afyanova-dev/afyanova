<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Domain\Model\Claim;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Claim aggregate root (IC §9; ADR-204).
 * First parameter of claim commands — bus resolves ClaimPolicy from it.
 */
interface ClaimRepository extends Repository
{
    public function getById(string $id): Claim;

    public function getByIdForUpdate(string $id): Claim;

    public function getByClaimNumber(string $claimNumber): ?Claim;

    public function existsActiveForInvoice(string $invoiceId): bool;
}
