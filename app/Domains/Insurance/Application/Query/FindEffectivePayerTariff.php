<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\PayerTariff;
use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Resolves the effective contracted tariff for a payer, service category, facility, and date (ADR-203).
 */
#[ProtectedQuery(ability: 'view', subjectType: PayerTariff::class)]
final readonly class FindEffectivePayerTariff
{
    public string $serviceCategory;

    public string $onDate;

    public function __construct(
        public string $payerId,
        string $serviceCategory,
        ?string $onDate = null,
        public ?string $facilityId = null,
    ) {
        if (! Str::isUuid($payerId)) {
            throw new InvalidArgumentException('FindEffectivePayerTariff::$payerId must be a UUID (IC §3).');
        }

        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('FindEffectivePayerTariff::$facilityId must be a UUID or null (IC §3).');
        }

        $this->serviceCategory = CoveredServiceCategory::assert($serviceCategory);
        $this->onDate = $onDate ?? CarbonImmutable::now()->toDateString();
    }
}
