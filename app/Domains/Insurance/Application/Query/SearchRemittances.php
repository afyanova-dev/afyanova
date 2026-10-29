<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Remittance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Search remittances by payer or status (21-insurance-and-payer-domain.md §98; ADR-205).
 * `view` gates on coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Remittance::class)]
final readonly class SearchRemittances
{
    public function __construct(
        public ?string $payerId = null,
        public ?string $status = null,
    ) {
        if ($payerId !== null && ! Str::isUuid($payerId)) {
            throw new InvalidArgumentException('SearchRemittances::$payerId must be a UUID (IC §3).');
        }
    }
}
