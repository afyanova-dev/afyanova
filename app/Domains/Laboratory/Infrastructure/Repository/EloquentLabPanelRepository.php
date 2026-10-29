<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Repository;

use App\Domains\Laboratory\Domain\LabPanelRepository;
use App\Domains\Laboratory\Domain\Model\LabPanel;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of LabPanelRepository (ADR-162 D-C).
 */
final class EloquentLabPanelRepository extends EloquentRepository implements LabPanelRepository
{
    protected function model(): string
    {
        return LabPanel::class;
    }

    public function getById(string $id): LabPanel
    {
        return LabPanel::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(LabPanel::class, $id);
    }

    public function getByIdForUpdate(string $id): LabPanel
    {
        return LabPanel::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(LabPanel::class, $id);
    }

    public function findActiveByCode(string $tenantId, string $code): ?LabPanel
    {
        return LabPanel::query()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->where('active', true)
            ->first();
    }
}
