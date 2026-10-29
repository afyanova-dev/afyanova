<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class DuplicatePositionCodeException extends DomainException
{
    public static function forCode(string $code): self
    {
        return new self(sprintf('Position with code [%s] already exists in this tenant.', $code));
    }

    public function errorCode(): string
    {
        return 'duplicate_position_code';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
