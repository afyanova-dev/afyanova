<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class PersonNotFoundException extends DomainException
{
    public static function forId(string $personId): self
    {
        return new self(sprintf('Person [%s] was not found.', $personId));
    }

    public function errorCode(): string
    {
        return 'person_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
