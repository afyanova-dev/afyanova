<?php

declare(strict_types=1);

namespace App\Domains\Billing\Infrastructure\Reader;

use App\Domains\Billing\Domain\FinancialControlSettingsReader;
use App\Domains\Billing\Domain\Model\FinancialControlSettings;

final class EloquentFinancialControlSettingsReader implements FinancialControlSettingsReader
{
    public function dualControlThreshold(string $kind): ?string
    {
        // RLS scopes the table to the current tenant; one row per tenant.
        return FinancialControlSettings::query()->first()?->thresholdFor($kind);
    }
}
