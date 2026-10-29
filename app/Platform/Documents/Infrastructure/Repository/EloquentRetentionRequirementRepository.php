<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Repository;

use App\Platform\Documents\Domain\Model\RetentionRequirement;
use App\Platform\Documents\Domain\Repository\RetentionRequirementRepositoryInterface;
use Carbon\CarbonImmutable;

final class EloquentRetentionRequirementRepository implements RetentionRequirementRepositoryInterface
{
    public function save(RetentionRequirement $requirement): void
    {
        $requirement->save();
    }

    public function findById(string $id): ?RetentionRequirement
    {
        return RetentionRequirement::query()->whereKey($id)->first();
    }

    /**
     * @return list<RetentionRequirement>
     */
    public function listActiveByDocument(string $documentId, ?CarbonImmutable $asOf = null): array
    {
        $time = $asOf ?? CarbonImmutable::now();

        /** @var list<RetentionRequirement> */
        return RetentionRequirement::query()
            ->where('document_id', $documentId)
            ->whereNull('released_at')
            ->where(function ($q) use ($time): void {
                $q->where('indefinite', true)
                    ->orWhere('retain_until', '>', $time);
            })
            ->get()
            ->all();
    }
}
