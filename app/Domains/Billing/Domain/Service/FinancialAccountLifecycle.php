<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Service;

use App\Domains\Billing\Domain\Exception\IllegalFinancialAccountTransition;
use App\Domains\Billing\Domain\ValueObject\FinancialAccountStatus;

/**
 * Financial Account lifecycle rules (20-billing-and-payments-domain.md §300 /
 * §301; ADR-187 D-B). Pure domain logic, no I/O.
 *
 *   (open)                              -> OPEN
 *   OPEN | ACTIVE | SETTLED -> CLOSED   (CloseFinancialAccount — terminal)
 *
 * The OPEN -> ACTIVE (first posted charge) and ACTIVE -> SETTLED (balance
 * returns to zero) transitions arrive with Bill.3 / Bill.4.
 */
final class FinancialAccountLifecycle
{
    public function assertCanClose(string $status): void
    {
        if (! in_array($status, FinancialAccountStatus::CLOSABLE, true)) {
            throw IllegalFinancialAccountTransition::fromStatus('CloseFinancialAccount', $status);
        }
    }
}
