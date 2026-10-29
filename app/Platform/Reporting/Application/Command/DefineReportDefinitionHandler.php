<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Domain\Model\ReportDefinition;

final class DefineReportDefinitionHandler
{
    public function __invoke(DefineReportDefinitionCommand $command): ReportDefinition
    {
        return ReportDefinition::updateOrCreate(
            [
                'tenant_id' => $command->tenantId,
                'code' => $command->code,
            ],
            [
                'name' => $command->name,
                'category' => $command->category,
                'description' => $command->description,
                'data_mart_code' => $command->dataMartCode,
                'query_template' => $command->queryTemplate,
                'schedule_cron' => $command->scheduleCron,
                'distribution_list' => $command->distributionList,
                'status' => 'ACTIVE',
            ]
        );
    }
}
