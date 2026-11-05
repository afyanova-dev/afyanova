<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Repository;

use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Platform\Documents\Domain\Model\Document;
use App\Platform\Documents\Domain\Model\DocumentAccessGrant;
use App\Platform\Documents\Domain\Repository\AccessGrantRepositoryInterface;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class EloquentAccessGrantRepository implements AccessGrantRepositoryInterface
{
    public function __construct(private readonly TenantContext $context) {}

    public function save(DocumentAccessGrant $grant): void
    {
        $facilityId = $this->context->facilityId();

        if ($facilityId === null || ! Document::query()
            ->whereKey($grant->document_id)
            ->where('facility_id', $facilityId)
            ->exists()) {
            throw new DocumentNotAvailableException('Document is outside the active facility.');
        }

        $grant->save();
    }

    public function findById(string $id): ?DocumentAccessGrant
    {
        return $this->query()->whereKey($id)->first();
    }

    public function delete(string $id): void
    {
        $this->query()->whereKey($id)->delete();
    }

    public function hasActiveGrant(string $documentId, string $principalId, string $scope, ?CarbonImmutable $asOf = null): bool
    {
        $time = $asOf ?? CarbonImmutable::now();

        return $this->query()
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
        return $this->query()
            ->where('document_id', $documentId)
            ->where('expires_at', '>', $time)
            ->get()
            ->all();
    }

    /**
     * @return Builder<DocumentAccessGrant>
     */
    private function query(): Builder
    {
        $query = DocumentAccessGrant::query();
        $facilityId = $this->context->facilityId();

        if ($facilityId !== null) {
            $query->whereHas('document', static fn (Builder $documentQuery): Builder => $documentQuery
                ->where('facility_id', $facilityId));
        } else {
            $query->whereHas('document', static fn (Builder $documentQuery): Builder => $documentQuery
                ->whereNull('facility_id'));
        }

        return $query;
    }
}
