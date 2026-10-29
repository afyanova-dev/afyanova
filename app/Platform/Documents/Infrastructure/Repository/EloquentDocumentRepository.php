<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Repository;

use App\Platform\Documents\Domain\Model\Document;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;

final class EloquentDocumentRepository implements DocumentRepositoryInterface
{
    public function save(Document $document): void
    {
        $document->save();
    }

    public function findById(string $id): ?Document
    {
        return Document::query()->whereKey($id)->first();
    }

    public function findByIdWithVersions(string $id): ?Document
    {
        return Document::query()
            ->with(['versions' => fn ($q) => $q->orderBy('version_no', 'asc'), 'currentVersion'])
            ->whereKey($id)
            ->first();
    }

    public function findBySourceEntity(string $sourceDomain, string $sourceEntityRef): ?Document
    {
        return Document::query()
            ->where('source_domain', $sourceDomain)
            ->where('source_entity_ref', $sourceEntityRef)
            ->latest('created_at')
            ->first();
    }

    /**
     * @return list<Document>
     */
    public function listByPatient(string $patientId): array
    {
        /** @var list<Document> */
        return Document::query()
            ->where('patient_id', $patientId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * @return list<Document>
     */
    public function listByEncounter(string $encounterId): array
    {
        /** @var list<Document> */
        return Document::query()
            ->where('encounter_id', $encounterId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }
}
