<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreatePayerPlan` used a `code` already taken for the payer
 * (21-insurance-and-payer-domain.md §12; ADR-198 D-H — `unique (tenant_id,
 * payer_id, code)`). Maps to HTTP 409.
 */
final class PayerPlanCodeTaken extends DomainException
{
    public static function code(string $payerId, string $code): self
    {
        return new self(sprintf(
            'A plan with code [%s] already exists for payer [%s].',
            $code,
            $payerId,
        ));
    }

    public function errorCode(): string
    {
        return 'payer_plan_code_taken';
    }
}
