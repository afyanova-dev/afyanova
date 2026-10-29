<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Repository;

use App\Platform\Documents\Domain\Model\RetentionRequirement;
use Carbon\CarbonImmutable;

interface RetentionRequirementRepositoryInterface
{
    public function save(RetentionRequirement $requirement): void;

    public function findById(string $id): ?RetentionRequirement;

    /**
     * @return list<RetentionRequirement>
     */
    public function listActiveByDocument(string $documentId, ?CarbonImmutable $asOf = null): array;
}
