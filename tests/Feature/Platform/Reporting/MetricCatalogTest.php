<?php

declare(strict_types=1);

use App\Platform\Reporting\Application\Command\DefineMetricCommand;
use App\Platform\Reporting\Application\Query\GetMetricQuery;
use App\Platform\Reporting\Application\Query\ListMetricsQuery;
use App\Platform\Reporting\Domain\ValueObject\ReportCategory;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;

require_once __DIR__.'/../../../../tests/Support/reporting.php';

it('enforces mandatory owning_domain for all metrics per Invariant 7.6', function (): void {
    $fx = reportingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $bus = app(CommandBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($bus, $tenantId): void {
        expect(fn () => $bus->dispatch(new DefineMetricCommand(
            tenantId: $tenantId,
            code: 'ORPHAN_METRIC',
            name: 'Orphan Metric with no domain',
            owningDomain: '',
            category: ReportCategory::CLINICAL,
        )))->toThrow(InvalidArgumentException::class);
    });
});

it('supports metric versioning and historical queries', function (): void {
    $fx = reportingFixture();
    $tenantId = (string) $fx['tenant']->id;
    $facilityId = (string) $fx['facility']->id;

    $commandBus = app(CommandBus::class);
    $queryBus = app(QueryBus::class);

    TenantContextScope::run($tenantId, $facilityId, function () use ($commandBus, $queryBus, $tenantId, $fx): void {
        $userId = (string) $fx['user']->id;

        // Version 1
        $m1 = $commandBus->dispatch(new DefineMetricCommand(
            tenantId: $tenantId,
            code: 'MORTALITY_RATE_24H',
            name: '24-hour Mortality Rate',
            owningDomain: 'Clinical',
            category: ReportCategory::CLINICAL,
            calculationLogic: 'deaths_within_24h / total_admissions * 100',
            unitOfMeasure: 'PERCENTAGE',
            signedOffBy: $userId,
        ));
        expect($m1->version)->toBe(1);

        // Version 2 with updated logic
        $m2 = $commandBus->dispatch(new DefineMetricCommand(
            tenantId: $tenantId,
            code: 'MORTALITY_RATE_24H',
            name: '24-hour Mortality Rate (Revised)',
            owningDomain: 'Clinical',
            category: ReportCategory::CLINICAL,
            calculationLogic: 'deaths_within_24h_excluding_doa / total_admissions * 100',
            unitOfMeasure: 'PERCENTAGE',
            signedOffBy: $userId,
        ));
        expect($m2->version)->toBe(2);

        // Query latest
        $latest = $queryBus->dispatch(new GetMetricQuery(tenantId: $tenantId, code: 'MORTALITY_RATE_24H'));
        expect($latest->version)->toBe(2)
            ->and($latest->name)->toBe('24-hour Mortality Rate (Revised)');

        // Query historical version 1
        $v1 = $queryBus->dispatch(new GetMetricQuery(tenantId: $tenantId, code: 'MORTALITY_RATE_24H', version: 1));
        expect($v1->version)->toBe(1)
            ->and($v1->name)->toBe('24-hour Mortality Rate');

        // List metrics
        $list = $queryBus->dispatch(new ListMetricsQuery(tenantId: $tenantId, owningDomain: 'Clinical'));
        expect($list->count())->toBe(2);
    });
});
