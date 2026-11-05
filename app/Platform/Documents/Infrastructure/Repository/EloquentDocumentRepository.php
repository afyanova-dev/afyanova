<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Repository;

use App\Models\User;
use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Platform\Documents\Domain\Model\Document;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

final class EloquentDocumentRepository implements DocumentRepositoryInterface
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ScopeChecker $scope,
    ) {}

    public function save(Document $document): void
    {
        $this->assertInActiveFacility($document->facility_id);
        $document->save();
    }

    public function findById(string $id): ?Document
    {
        return $this->query()->whereKey($id)->first();
    }

    public function findByIdForCapabilityToken(string $id): ?Document
    {
        return Document::query()->whereKey($id)->first();
    }

    public function findByIdWithVersions(string $id): ?Document
    {
        return $this->query()
            ->with(['versions' => fn ($q) => $q->orderBy('version_no', 'asc'), 'currentVersion'])
            ->whereKey($id)
            ->first();
    }

    public function findByIdWithVersionsForCapabilityToken(string $id): ?Document
    {
        return Document::query()
            ->with(['versions' => fn ($q) => $q->orderBy('version_no', 'asc'), 'currentVersion'])
            ->whereKey($id)
            ->first();
    }

    public function findBySourceEntity(string $sourceDomain, string $sourceEntityRef): ?Document
    {
        return $this->query()
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
        return $this->query()
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
        return $this->query()
            ->where('encounter_id', $encounterId)
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * @return Builder<Document>
     */
    private function query(): Builder
    {
        $query = Document::query();
        $facilityId = $this->context->facilityId();

        if ($facilityId !== null) {
            $query->where('facility_id', $facilityId);
        } elseif ($this->context->isGeneral()) {
            $user = Auth::user();
            $facilityIds = $user instanceof User
                ? $this->scope->facilityIdsWithPermissionInContext(
                    $user,
                    $this->context,
                    'documents.document.view',
                )
                : [];

            $query->whereIn('facility_id', $facilityIds);
        } else {
            $query->whereNull('facility_id');
        }

        return $query;
    }

    private function assertInActiveFacility(string $facilityId): void
    {
        $activeFacilityId = $this->context->facilityId();

        if ($activeFacilityId === null || $activeFacilityId !== $facilityId) {
            throw new DocumentNotAvailableException('Document is outside the active facility.');
        }
    }
}
