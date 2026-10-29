<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class PositionNotFoundException extends DomainException
{
    public static function forId(string $positionId): self
    {
        return new self(sprintf('Position [%s] was not found.', $positionId));
    }

    public static function forCode(string $code): self
    {
        return new self(sprintf('Position with code [%s] was not found.', $code));
    }

    public function errorCode(): string
    {
        return 'position_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
