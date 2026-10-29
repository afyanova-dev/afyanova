<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Infrastructure\Reader;

use App\Domains\Procurement\Domain\EvaluationCriteria;
use App\Domains\Procurement\Domain\EvaluationSettingsReader;
use App\Domains\Procurement\Domain\Model\EvaluationSettings;

final class EloquentEvaluationSettingsReader implements EvaluationSettingsReader
{
    public function forTenant(): EvaluationCriteria
    {
        // RLS scopes `pcr_evaluation_settings` to the current tenant, and there
        // is exactly one row per tenant — so `first()` is the tenant's row.
        $settings = EvaluationSettings::query()->first();

        return $settings?->toCriteria() ?? EvaluationCriteria::defaults();
    }
}
