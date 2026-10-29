<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class DuplicateShiftPatternCodeException extends DomainException
{
    public static function forCode(string $code, string $facilityId): self
    {
        return new self(sprintf(
            'Shift pattern with code [%s] already exists for facility [%s]. Codes must be unique per facility.',
            $code,
            $facilityId
        ));
    }

    public function errorCode(): string
    {
        return 'duplicate_shift_pattern_code';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
