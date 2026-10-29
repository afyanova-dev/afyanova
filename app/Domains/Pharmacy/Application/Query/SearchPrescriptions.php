<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\ValueObject\PrescriptionStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The prescription queue at one facility (16-pharmacy-domain.md §186; ADR-181
 * D-F). Optional status filter. `view` gates on `pharmacy.access`; the handler
 * re-checks the acting user's `pharmacy.access` scope at the facility (the
 * `GetStockAdjustments` acting-user pattern).
 */
#[ProtectedQuery(ability: 'view', subjectType: Prescription::class)]
final readonly class SearchPrescriptions
{
    public function __construct(
        public string $facilityId,
        public ?string $status = null,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('SearchPrescriptions::$facilityId must be a UUID (IC §3).');
        }

        if ($status !== null && ! PrescriptionStatus::isValid(strtoupper(trim($status)))) {
            throw new InvalidArgumentException('SearchPrescriptions::$status must be a valid PrescriptionStatus or null.');
        }
    }
}
