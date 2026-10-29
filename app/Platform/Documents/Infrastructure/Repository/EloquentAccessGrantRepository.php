<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Repository;

use App\Platform\Documents\Domain\Model\DocumentAccessGrant;
use App\Platform\Documents\Domain\Repository\AccessGrantRepositoryInterface;
use Carbon\CarbonImmutable;

final class EloquentAccessGrantRepository implements AccessGrantRepositoryInterface
{
    public function save(DocumentAccessGrant $grant): void
    {
        $grant->save();
    }

    public function findById(string $id): ?DocumentAccessGrant
    {
        return DocumentAccessGrant::query()->whereKey($id)->first();
    }

    public function delete(string $id): void
    {
        DocumentAccessGrant::query()->whereKey($id)->delete();
    }

    public function hasActiveGrant(string $documentId, string $principalId, string $scope, ?CarbonImmutable $asOf = null): bool
    {
        $time = $asOf ?? CarbonImmutable::now();

        return DocumentAccessGrant::query()
            ->where('document_id', $documentId)
            ->where('principal_id', $principalId)
            ->where(function ($q) use ($scope): void {
                $q->where('scope', $scope)
                    ->orWhere('scope', '*');
            })
            ->where('expires_at', '>', $time)
            ->exists();
    }

    /**
     * @return list<DocumentAccessGrant>
     */
    public function listActiveGrants(string $documentId, ?CarbonImmutable $asOf = null): array
    {
        $time = $asOf ?? CarbonImmutable::now();

        /** @var list<DocumentAccessGrant> */
        return DocumentAccessGrant::query()
            ->where('document_id', $documentId)
            ->where('expires_at', '>', $time)
            ->get()
            ->all();
    }
}
