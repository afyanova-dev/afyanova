<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\PayerTariff;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * List tariffs for a contract (21-insurance-and-payer-domain.md §51–§55; ADR-203).
 */
#[ProtectedQuery(ability: 'view', subjectType: PayerTariff::class)]
final readonly class GetContractTariffs
{
    public ?string $serviceCategory;

    public function __construct(
        public string $contractId,
        ?string $serviceCategory = null,
    ) {
        if (! Str::isUuid($contractId)) {
            throw new InvalidArgumentException('GetContractTariffs::$contractId must be a UUID (IC §3).');
        }

        $this->serviceCategory = $serviceCategory !== null ? strtoupper(trim($serviceCategory)) : null;
    }
}
