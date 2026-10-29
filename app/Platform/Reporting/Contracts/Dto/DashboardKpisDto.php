<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Contracts\Dto;

final readonly class DashboardKpisDto
{
    /**
     * @param  array{total: int, admissions: int, discharges: int, emergencies: int}  $encounters
     * @param  array{total_billed: float, total_collected: float}  $financials
     * @param  array{total_beds: int, occupied_beds: int, rate_percent: float}  $bedOccupancy
     * @param  array{tests_completed: int, avg_tat_minutes: float}  $lab
     * @param  array{total_prescriptions: int, total_active: int, total_cancelled: int}|null  $pharmacy
     */
    public function __construct(
        public string $facilityId,
        public string $asOfDate,
        public array $encounters,
        public array $financials,
        public array $bedOccupancy,
        public array $lab,
        public ?array $pharmacy = null,
    ) {}
}
