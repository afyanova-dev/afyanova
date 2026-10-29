<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Repository;

use App\Platform\Documents\Domain\Model\DocumentVersion;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;

final class EloquentDocumentVersionRepository implements DocumentVersionRepositoryInterface
{
    public function save(DocumentVersion $version): void
    {
        $version->save();
    }

    public function findById(string $id): ?DocumentVersion
    {
        return DocumentVersion::query()->whereKey($id)->first();
    }

    public function findByIdForUpdate(string $id): ?DocumentVersion
    {
        return DocumentVersion::query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findByDocumentAndVersion(string $documentId, int $versionNo): ?DocumentVersion
    {
        return DocumentVersion::query()
            ->where('document_id', $documentId)
            ->where('version_no', $versionNo)
            ->first();
    }

    public function getLatestVersion(string $documentId): ?DocumentVersion
    {
        return DocumentVersion::query()
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
        return DocumentVersion::query()
            ->where('document_id', $documentId)
            ->orderBy('version_no', 'asc')
            ->get()
            ->all();
    }
}
