<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Repository;

use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Platform\Documents\Domain\Model\Document;
use App\Platform\Documents\Domain\Model\RetentionRequirement;
use App\Platform\Documents\Domain\Repository\RetentionRequirementRepositoryInterface;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class EloquentRetentionRequirementRepository implements RetentionRequirementRepositoryInterface
{
    public function __construct(private readonly TenantContext $context) {}

    public function save(RetentionRequirement $requirement): void
    {
        $facilityId = $this->context->facilityId();

        if ($facilityId === null || ! Document::query()
            ->whereKey($requirement->document_id)
            ->where('facility_id', $facilityId)
            ->exists()) {
            throw new DocumentNotAvailableException('Document is outside the active facility.');
        }

        $requirement->save();
    }

    public function findById(string $id): ?RetentionRequirement
    {
        return $this->query()->whereKey($id)->first();
    }

    /**
     * @return list<RetentionRequirement>
     */
    public function listActiveByDocument(string $documentId, ?CarbonImmutable $asOf = null): array
    {
        $time = $asOf ?? CarbonImmutable::now();

        /** @var list<RetentionRequirement> */
        return $this->query()
            ->where('document_id', $documentId)
            ->whereNull('released_at')
            ->where(function ($q) use ($time): void {
                $q->where('indefinite', true)
                    ->orWhere('retain_until', '>', $time);
            })
            ->get()
            ->all();
    }

    /**
     * @return Builder<RetentionRequirement>
     */
    private function query(): Builder
    {
        $query = RetentionRequirement::query();
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
