<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Authorization;
use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Finds the currently effective authorization covering a date for a patient and service category
 * (21-insurance-and-payer-domain.md §56–§65; ADR-202 D-C).
 * `view` gates on the coarse `insurance.access`.
 *
 * Returns ?AuthorizationSummary (null when none).
 */
#[ProtectedQuery(ability: 'view', subjectType: Authorization::class)]
final readonly class FindEffectiveAuthorization
{
    public string $serviceCategory;

    public ?string $onDate;

    public function __construct(
        public string $patientId,
        string $serviceCategory,
        ?string $onDate = null,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('FindEffectiveAuthorization::$patientId must be a UUID (IC §3).');
        }

        $this->serviceCategory = CoveredServiceCategory::assert($serviceCategory);

        if ($onDate !== null && trim($onDate) !== '') {
            try {
                $this->onDate = CarbonImmutable::parse($onDate)->toDateString();
            } catch (\Throwable) {
                throw new InvalidArgumentException('FindEffectiveAuthorization::$onDate must be a valid date or null (ADR-202 D-C).');
            }
        } else {
            $this->onDate = null;
        }
    }
}
