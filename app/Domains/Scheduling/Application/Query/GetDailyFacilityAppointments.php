<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Domain\Model\Appointment;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Facility appointment schedule collection read (ADR-207; Increment C).
 *
 * Protected (IC §6.2) — enforces coarse tenant + `sch.access` via `AppointmentPolicy::view`.
 * Facility scope is enforced in the handler via `ScopeChecker`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Appointment::class)]
final readonly class GetDailyFacilityAppointments
{
    public function __construct(
        public string $facilityId,
        public CarbonImmutable $date,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException("facilityId must be a valid UUID, got '{$facilityId}'.");
        }
    }
}
