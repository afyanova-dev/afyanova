<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RegisterMemberCoverage` targeted a plan (or its payer) that is not ACTIVE
 * (ADR-198 D-H / ADR-199 D-E — new member coverage requires both ACTIVE).
 * Maps to HTTP 409.
 */
final class PayerOrPlanInactive extends DomainException
{
    public static function plan(string $planId): self
    {
        return new self(sprintf('Plan [%s] is not ACTIVE — cannot register new member coverage.', $planId));
    }

    public static function payer(string $payerId): self
    {
        return new self(sprintf('Payer [%s] is not ACTIVE — cannot register new member coverage.', $payerId));
    }

    public function errorCode(): string
    {
        return 'payer_or_plan_inactive';
    }
}
