<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Claim;
use App\Domains\Insurance\Domain\ValueObject\ClaimStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read claims for a patient (21-insurance-and-payer-domain.md §76; ADR-204).
 * `view` gates on coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Claim::class)]
final readonly class GetPatientClaims
{
    public ?string $status;

    public function __construct(
        public string $patientId,
        ?string $status = null,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientClaims::$patientId must be a UUID (IC §3).');
        }

        $this->status = $status !== null ? ClaimStatus::assert($status) : null;
    }
}
