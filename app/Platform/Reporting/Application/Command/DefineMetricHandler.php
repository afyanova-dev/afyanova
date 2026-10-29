<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Domain\Model\MetricDefinition;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class DefineMetricHandler
{
    public function __invoke(DefineMetricCommand $command): MetricDefinition
    {
        $owningDomain = trim($command->owningDomain);
        if ($owningDomain === '') {
            throw new InvalidArgumentException('Metric must specify an owning_domain (doc 29 §7.6).');
        }

        /** @var int|null $latestVersion */
        $latestVersion = MetricDefinition::query()
            ->where('tenant_id', $command->tenantId)
            ->where('code', $command->code)
            ->max('version');

        $version = ($latestVersion ?? 0) + 1;

        return MetricDefinition::create([
            'tenant_id' => $command->tenantId,
            'code' => $command->code,
            'name' => $command->name,
            'owning_domain' => $owningDomain,
            'category' => $command->category,
            'description' => $command->description,
            'calculation_logic' => $command->calculationLogic,
            'unit_of_measure' => $command->unitOfMeasure,
            'version' => $version,
            'status' => 'ACTIVE',
            'signed_off_by' => $command->signedOffBy,
            'signed_off_at' => $command->signedOffBy !== null ? CarbonImmutable::now() : null,
        ]);
    }
}
