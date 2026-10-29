<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Repository;

use App\Platform\Documents\Domain\Model\DocumentVersion;

interface DocumentVersionRepositoryInterface
{
    public function save(DocumentVersion $version): void;

    public function findById(string $id): ?DocumentVersion;

    public function findByIdForUpdate(string $id): ?DocumentVersion;

    public function findByDocumentAndVersion(string $documentId, int $versionNo): ?DocumentVersion;

    public function getLatestVersion(string $documentId): ?DocumentVersion;

    /**
     * @return list<DocumentVersion>
     */
    public function listByDocument(string $documentId): array;
}
