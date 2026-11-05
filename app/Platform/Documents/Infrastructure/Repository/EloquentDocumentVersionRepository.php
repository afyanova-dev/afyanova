<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Repository;

use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Platform\Documents\Domain\Model\Document;
use App\Platform\Documents\Domain\Model\DocumentVersion;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

final class EloquentDocumentVersionRepository implements DocumentVersionRepositoryInterface
{
    public function __construct(private readonly TenantContext $context) {}

    public function save(DocumentVersion $version): void
    {
        $facilityId = $this->context->facilityId();

        if ($facilityId === null || ! Document::query()
            ->whereKey($version->document_id)
            ->where('facility_id', $facilityId)
            ->exists()) {
            throw new DocumentNotAvailableException('Document is outside the active facility.');
        }

        $version->save();
    }

    public function findById(string $id): ?DocumentVersion
    {
        return $this->query()->whereKey($id)->first();
    }

    public function findByIdForUpdate(string $id): ?DocumentVersion
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findByDocumentAndVersion(string $documentId, int $versionNo): ?DocumentVersion
    {
        return $this->query()
            ->where('document_id', $documentId)
            ->where('version_no', $versionNo)
            ->first();
    }

    public function findByDocumentAndVersionForCapabilityToken(string $documentId, int $versionNo): ?DocumentVersion
    {
        return DocumentVersion::query()
            ->where('document_id', $documentId)
            ->where('version_no', $versionNo)
            ->first();
    }

    public function getLatestVersion(string $documentId): ?DocumentVersion
    {
        return $this->query()
            ->where('document_id', $documentId)
            ->orderByDesc('version_no')
            ->first();
    }

    /**
     * @return list<DocumentVersion>
     */
    public function listByDocument(string $documentId): array
    {
        /** @var list<DocumentVersion> */
        return $this->query()
            ->where('document_id', $documentId)
            ->orderBy('version_no', 'asc')
            ->get()
            ->all();
    }

    /**
     * @return Builder<DocumentVersion>
     */
    private function query(): Builder
    {
        $query = DocumentVersion::query();
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
