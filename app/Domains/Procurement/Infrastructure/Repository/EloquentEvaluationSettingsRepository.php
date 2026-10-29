<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Repository;

use App\Domains\Procurement\Domain\EvaluationSettingsRepository;
use App\Domains\Procurement\Domain\Model\EvaluationSettings;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

final class EloquentEvaluationSettingsRepository extends EloquentRepository implements EvaluationSettingsRepository
{
    protected function model(): string
    {
        return EvaluationSettings::class;
    }

    public function getById(string $id): EvaluationSettings
    {
        return EvaluationSettings::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(EvaluationSettings::class, $id);
    }

    public function getByIdForUpdate(string $id): EvaluationSettings
    {
        return EvaluationSettings::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(EvaluationSettings::class, $id);
    }

    public function findForTenant(string $tenantId): ?EvaluationSettings
    {
        return EvaluationSettings::query()->where('tenant_id', $tenantId)->first();
    }
}
