<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Infrastructure\Repository;

use App\Domains\Insurance\Domain\AdjudicationRepository;
use App\Domains\Insurance\Domain\Exception\AdjudicationNotFound;
use App\Domains\Insurance\Domain\Model\Adjudication;
use App\Domains\Insurance\Domain\Model\AdjudicationLine;
use App\Domains\Insurance\Domain\ValueObject\AdjudicationStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of {@see AdjudicationRepository} (IC §9; ADR-205).
 */
final class EloquentAdjudicationRepository extends EloquentRepository implements AdjudicationRepository
{
    protected function model(): string
    {
        return Adjudication::class;
    }

    public function getById(string $id): Adjudication
    {
        return Adjudication::query()->with('lines')->whereKey($id)->first()
            ?? throw AdjudicationNotFound::id($id);
    }

    public function getByIdForUpdate(string $id): Adjudication
    {
        return Adjudication::query()->with('lines')->whereKey($id)->lockForUpdate()->first()
            ?? throw AdjudicationNotFound::id($id);
    }

    public function getByClaimId(string $claimId): ?Adjudication
    {
        return Adjudication::query()->with('lines')->where('claim_id', $claimId)->first();
    }

    public function existsForClaim(string $claimId): bool
    {
        return Adjudication::query()
            ->where('claim_id', $claimId)
            ->whereIn('status', [
                AdjudicationStatus::APPROVED,
                AdjudicationStatus::PARTIALLY_APPROVED,
                AdjudicationStatus::DENIED,
            ])
            ->exists();
    }

    public function save(AggregateRoot $aggregate): void
    {
        parent::save($aggregate);

        if ($aggregate instanceof Adjudication && $aggregate->relationLoaded('lines')) {
            /** @var AdjudicationLine $line */
            foreach ($aggregate->lines as $line) {
                $line->save();
            }
        }
    }
}
