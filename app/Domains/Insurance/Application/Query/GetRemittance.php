<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Remittance;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one remittance by ID (21-insurance-and-payer-domain.md §98; ADR-205).
 * `view` gates on coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Remittance::class)]
final readonly class GetRemittance
{
    public function __construct(public string $remittanceId)
    {
        if (! Str::isUuid($remittanceId)) {
            throw new InvalidArgumentException('GetRemittance::$remittanceId must be a UUID (IC §3).');
        }
    }
}
