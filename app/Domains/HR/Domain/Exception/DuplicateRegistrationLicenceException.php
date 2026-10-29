<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class DuplicateRegistrationLicenceException extends DomainException
{
    public static function forLicence(string $council, string $licenceNumber): self
    {
        return new self(sprintf(
            'A professional registration already exists for council [%s] with licence number [%s].',
            $council,
            $licenceNumber
        ));
    }

    public function errorCode(): string
    {
        return 'duplicate_registration_licence';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
