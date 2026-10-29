<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Repository;

use App\Domains\Billing\Domain\FinancialControlSettingsRepository;
use App\Domains\Billing\Domain\Model\FinancialControlSettings;
use App\Shared\Domain\EloquentRepository;

final class EloquentFinancialControlSettingsRepository extends EloquentRepository implements FinancialControlSettingsRepository
{
    protected function model(): string
    {
        return FinancialControlSettings::class;
    }

    public function findForTenant(string $tenantId): ?FinancialControlSettings
    {
        return FinancialControlSettings::query()->where('tenant_id', $tenantId)->first();
    }
}
