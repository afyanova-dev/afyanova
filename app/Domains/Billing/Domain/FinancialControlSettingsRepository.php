<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\FinancialControlSettings;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the per-tenant financial-control thresholds (IC §9;
 * ADR-191 G-3a). Written ONLY by the `billing:configure-financial-control`
 * console command.
 */
interface FinancialControlSettingsRepository extends Repository
{
    public function findForTenant(string $tenantId): ?FinancialControlSettings;
}
