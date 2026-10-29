<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Payer;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one payer master entry (21-insurance-and-payer-domain.md §9; ADR-198
 * D-I). `view` gates on the coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Payer::class)]
final readonly class GetPayer
{
    public function __construct(public string $payerId)
    {
        if (! Str::isUuid($payerId)) {
            throw new InvalidArgumentException('GetPayer::$payerId must be a UUID (IC §3).');
        }
    }
}
