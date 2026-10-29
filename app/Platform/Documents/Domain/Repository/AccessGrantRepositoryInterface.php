<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Repository;

use App\Platform\Documents\Domain\Model\DocumentAccessGrant;
use Carbon\CarbonImmutable;

interface AccessGrantRepositoryInterface
{
    public function save(DocumentAccessGrant $grant): void;

    public function findById(string $id): ?DocumentAccessGrant;

    public function delete(string $id): void;

    public function hasActiveGrant(string $documentId, string $principalId, string $scope, ?CarbonImmutable $asOf = null): bool;

    /**
     * @return list<DocumentAccessGrant>
     */
    public function listActiveGrants(string $documentId, ?CarbonImmutable $asOf = null): array;
}
