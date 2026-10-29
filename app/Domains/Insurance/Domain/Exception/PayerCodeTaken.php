<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RegisterPayer` used a `code` already taken in the tenant
 * (21-insurance-and-payer-domain.md §9; ADR-198 D-G — `unique (tenant_id,
 * code)`). Maps to HTTP 409.
 */
final class PayerCodeTaken extends DomainException
{
    public static function code(string $code): self
    {
        return new self(sprintf('A payer with code [%s] already exists in this tenant.', $code));
    }

    public function errorCode(): string
    {
        return 'payer_code_taken';
    }
}
