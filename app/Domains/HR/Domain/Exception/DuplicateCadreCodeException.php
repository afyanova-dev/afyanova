<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class DuplicateCadreCodeException extends DomainException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('Cadre with code [%s] already exists in this tenant.', $code));
    }

    public function errorCode(): string
    {
        return 'duplicate_cadre_code';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
