<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class CadreNotFoundException extends DomainException
{
    public static function forId(string $cadreId): self
    {
        return new self(sprintf('Cadre [%s] was not found.', $cadreId));
    }

    public static function forCode(string $code): self
    {
        return new self(sprintf('Cadre with code [%s] was not found.', $code));
    }

    public function errorCode(): string
    {
        return 'cadre_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
