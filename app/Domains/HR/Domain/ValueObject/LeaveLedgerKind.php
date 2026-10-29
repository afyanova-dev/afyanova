<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Classification kinds for append-only leave ledger entries (25-hr-and-workforce-domain.md §6, §7.9).
 */
enum LeaveLedgerKind: string
{
    case OPENING = 'OPENING';
    case ACCRUAL = 'ACCRUAL';
    case TAKEN = 'TAKEN';
    case ADJUSTMENT = 'ADJUSTMENT';
    case CARRY_OVER = 'CARRY_OVER';
    case EXPIRY = 'EXPIRY';
    case ENCASHMENT = 'ENCASHMENT';

    public function isDebit(): bool
    {
        return in_array($this, [self::TAKEN, self::EXPIRY, self::ENCASHMENT], true);
    }

    public function isCredit(): bool
    {
        return in_array($this, [self::OPENING, self::ACCRUAL, self::CARRY_OVER], true);
    }
}
