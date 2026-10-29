<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class ShiftPatternNotFoundException extends DomainException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Shift pattern [%s] not found.', $id));
    }

    public static function forCode(string $code, string $facilityId): self
    {
        return new self(sprintf('Shift pattern with code [%s] not found for facility [%s].', $code, $facilityId));
    }

    public function errorCode(): string
    {
        return 'shift_pattern_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
