<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Service;

use App\Domains\Procurement\Domain\Exception\IllegalSupplierTransition;
use App\Domains\Procurement\Domain\ValueObject\SupplierStatus;

/**
 * Supplier lifecycle rules (19-procurement-domain.md §12, §14, §15; ADR-171 D-C
 * — F5a). Pure domain logic, no I/O. PROSPECT + the §13 onboarding flow are
 * deferred to Proc.4.
 *
 * The transition table itself lives in {@see SupplierStatus} so the screen can
 * offer exactly the moves the guard will accept. This service is the write-side
 * guard.
 */
final class SupplierLifecycle
{
    public function assertCanTransition(string $from, string $to): void
    {
        if (! in_array($to, SupplierStatus::allowedFrom($from), true)) {
            throw IllegalSupplierTransition::from($from, $to);
        }
    }
}
