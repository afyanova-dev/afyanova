<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Repository;

use App\Domains\Insurance\Domain\ClaimRepository;
use App\Domains\Insurance\Domain\Exception\ClaimNotFound;
use App\Domains\Insurance\Domain\Model\Claim;
use App\Domains\Insurance\Domain\ValueObject\ClaimStatus;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of {@see ClaimRepository} (IC §9; ADR-204).
 */
final class EloquentClaimRepository extends EloquentRepository implements ClaimRepository
{
    protected function model(): string
    {
        return Claim::class;
    }

    public function getById(string $id): Claim
    {
        return Claim::query()->whereKey($id)->first()
            ?? throw ClaimNotFound::id($id);
    }

    public function getByIdForUpdate(string $id): Claim
    {
        return Claim::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw ClaimNotFound::id($id);
    }

    public function getByClaimNumber(string $claimNumber): ?Claim
    {
        return Claim::query()->where('claim_number', $claimNumber)->first();
    }

    public function existsActiveForInvoice(string $invoiceId): bool
    {
        return Claim::query()
            ->where('invoice_id', $invoiceId)
            ->whereNotIn('status', [ClaimStatus::CANCELLED, ClaimStatus::SUPERSEDED])
            ->exists();
    }
}
