<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Repository;

use App\Platform\Documents\Domain\Model\Document;

interface DocumentRepositoryInterface
{
    public function save(Document $document): void;

    public function findById(string $id): ?Document;

    public function findByIdWithVersions(string $id): ?Document;

    public function findBySourceEntity(string $sourceDomain, string $sourceEntityRef): ?Document;

    /**
     * @return list<Document>
     */
    public function listByPatient(string $patientId): array;

    /**
     * @return list<Document>
     */
    public function listByEncounter(string $encounterId): array;
}
