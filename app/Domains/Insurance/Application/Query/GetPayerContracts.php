<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\PayerContract;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * List contracts for a payer (21-insurance-and-payer-domain.md §49; ADR-203).
 */
#[ProtectedQuery(ability: 'view', subjectType: PayerContract::class)]
final readonly class GetPayerContracts
{
    public ?string $status;

    public function __construct(
        public string $payerId,
        ?string $status = null,
    ) {
        if (! Str::isUuid($payerId)) {
            throw new InvalidArgumentException('GetPayerContracts::$payerId must be a UUID (IC §3).');
        }

        $this->status = $status !== null ? strtoupper(trim($status)) : null;
    }
}
