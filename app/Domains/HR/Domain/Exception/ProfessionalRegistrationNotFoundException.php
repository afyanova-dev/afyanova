<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class ProfessionalRegistrationNotFoundException extends DomainException
{
    public static function forId(string $registrationId): self
    {
        return new self(sprintf('Professional registration [%s] not found.', $registrationId));
    }

    public function errorCode(): string
    {
        return 'professional_registration_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
