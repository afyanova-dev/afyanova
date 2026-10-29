<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RequestWriteOff` / `RequestAdjustment` named a CLOSED Financial Account
 * (§301 — a closed account is terminal at a zero balance; ADR-191 D7). Maps to
 * HTTP 409.
 */
final class CannotControlClosedAccount extends DomainException
{
    public static function account(string $financialAccountId): self
    {
        return new self(sprintf(
            'Financial account [%s] is CLOSED — no write-off or adjustment may be raised against it (§301).',
            $financialAccountId,
        ));
    }

    public function errorCode(): string
    {
        return 'cannot_control_closed_account';
    }
}
