<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\PayerContract;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one payer contract (21-insurance-and-payer-domain.md §49; ADR-203).
 */
#[ProtectedQuery(ability: 'view', subjectType: PayerContract::class)]
final readonly class GetPayerContract
{
    public function __construct(public string $contractId)
    {
        if (! Str::isUuid($contractId)) {
            throw new InvalidArgumentException('GetPayerContract::$contractId must be a UUID (IC §3).');
        }
    }
}
