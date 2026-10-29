<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Infrastructure\Service;

use App\Platform\Reporting\Application\Query\GetDashboardSummaryQuery;
use App\Platform\Reporting\Application\Query\GetProjectionStatusQuery;
use App\Platform\Reporting\Contracts\Dto\DashboardKpisDto;
use App\Platform\Reporting\Contracts\Dto\ProjectionHealthDto;
use App\Platform\Reporting\Contracts\ReportingApi;
use App\Platform\Reporting\Domain\Model\MetricDefinition;
use App\Platform\Reporting\Domain\Model\ProjectionCheckpoint;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

final readonly class ReportingApiService implements ReportingApi
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    private function tenantId(): string
    {
        return app(TenantContext::class)->tenantId();
    }

    public function getDashboardKpis(string $facilityId, ?string $date = null): DashboardKpisDto
    {
        /** @var array{
         *     facility_id: string,
         *     as_of_date: string,
         *     encounters: array{total: int, admissions: int, discharges: int, emergencies: int},
         *     financials: array{total_billed: float, total_collected: float},
         *     bed_occupancy: array{total_beds: int, occupied_beds: int, rate_percent: float},
         *     lab: array{tests_completed: int, avg_tat_minutes: float},
         *     pharmacy: array{total_prescriptions: int, total_active: int, total_cancelled: int}
         * } $res */
        $res = $this->queryBus->dispatch(new GetDashboardSummaryQuery(
            tenantId: $this->tenantId(),
            facilityId: $facilityId,
            date: $date,
        ));

        return new DashboardKpisDto(
            facilityId: $res['facility_id'],
            asOfDate: $res['as_of_date'],
            encounters: $res['encounters'],
            financials: $res['financials'],
            bedOccupancy: $res['bed_occupancy'],
            lab: $res['lab'],
            pharmacy: $res['pharmacy'],
        );
    }

    public function getProjectionHealth(string $streamName): ?ProjectionHealthDto
    {
        /** @var ProjectionCheckpoint|null $checkpoint */
        $checkpoint = $this->queryBus->dispatch(new GetProjectionStatusQuery(
            tenantId: $this->tenantId(),
            streamName: $streamName,
        ));

        if ($checkpoint === null) {
            return null;
        }

        $now = CarbonImmutable::now();
        $lagSeconds = $checkpoint->last_rebuilt_at !== null
            ? (int) max(0, $now->diffInSeconds($checkpoint->last_rebuilt_at, true))
            : 0;

        return new ProjectionHealthDto(
            streamName: $checkpoint->stream_name,
            status: $checkpoint->status->value,
            checkpointOffset: (int) $checkpoint->checkpoint_offset,
            lastEventTimestamp: $checkpoint->last_event_timestamp,
            lastRebuiltAt: $checkpoint->last_rebuilt_at,
            referenceConfigVersion: $checkpoint->reference_config_version,
            lagSeconds: $lagSeconds,
        );
    }

    public function isMetricActive(string $metricKey): bool
    {
        return MetricDefinition::query()
            ->where('tenant_id', $this->tenantId())
            ->where('code', $metricKey)
            ->where('status', 'ACTIVE')
            ->exists();
    }
}
