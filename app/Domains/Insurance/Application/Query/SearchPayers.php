<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Payer;
use App\Domains\Insurance\Domain\ValueObject\PayerStatus;
use App\Domains\Insurance\Domain\ValueObject\PayerType;
use App\Shared\Bus\ProtectedQuery;
use InvalidArgumentException;

/**
 * The tenant's payer master, optionally filtered by type / status
 * (21-insurance-and-payer-domain.md §9; ADR-198 D-I). `view` gates on the
 * coarse `insurance.access`; the master is tenant-wide (no facility scope).
 */
#[ProtectedQuery(ability: 'view', subjectType: Payer::class)]
final readonly class SearchPayers
{
    public ?string $payerType;

    public ?string $status;

    public function __construct(?string $payerType = null, ?string $status = null)
    {
        if ($payerType !== null && ! PayerType::isValid($payerType)) {
            throw new InvalidArgumentException('SearchPayers::$payerType must be a valid payer type or null.');
        }
        if ($status !== null && ! PayerStatus::isValid($status)) {
            throw new InvalidArgumentException('SearchPayers::$status must be ACTIVE | INACTIVE or null.');
        }

        $this->payerType = $payerType !== null ? strtoupper(trim($payerType)) : null;
        $this->status = $status !== null ? strtoupper(trim($status)) : null;
    }
}
