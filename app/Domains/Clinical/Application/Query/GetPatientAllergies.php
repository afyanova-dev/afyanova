<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\Allergy;
use App\Domains\Clinical\Domain\ValueObject\AllergyStatus;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one patient's allergy safety list (12-clinical-domain.md §78, §164;
 * ADR-141 D-O / D-N). Protected (IC §6.2).
 *
 * `$clinicalStatus` (`ACTIVE` | `RESOLVED` | `INACTIVE`) and / or
 * `$verificationStatus` (`SUSPECTED` | `CONFIRMED` | `REFUTED`) narrow the list;
 * both null returns every allergy for the patient in the caller's tenant.
 * `GetPatientAllergies($patientId, 'ACTIVE', null)` is the canonical
 * "active allergies" read (ADR-141 D-N — there is NO separate active query).
 *
 * >>> Q7 (ADR-141 D-M / D-N): the result is TENANT-WIDE — `AllergyReader::forPatient`
 *     takes NO `$inScopeFacilityIds` and `GetPatientAllergiesHandler` computes
 *     no facility whitelist (unlike `GetPatientProblems`). RLS confines the
 *     rows to the caller's tenant. <<<
 *
 * Deterministic ordering; NO pagination (ADR-141 D-N). Read-only — no `clin_rm_*`
 * projection, no `ClinicalApi` method.
 */
#[ProtectedQuery(ability: 'view', subjectType: Allergy::class)]
final readonly class GetPatientAllergies
{
    public function __construct(
        public string $patientId,
        public ?string $clinicalStatus = null,
        public ?string $verificationStatus = null,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientAllergies::$patientId must be a UUID (IC §3).');
        }

        if ($clinicalStatus !== null) {
            AllergyStatus::assert($clinicalStatus);
        }

        if ($verificationStatus !== null) {
            AllergyVerificationStatus::assert($verificationStatus);
        }
    }
}
