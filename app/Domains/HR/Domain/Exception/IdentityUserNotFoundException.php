<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Exception;

use App\Shared\Domain\DomainException;

final class IdentityUserNotFoundException extends DomainException
{
    public static function forId(string $userId): self
    {
        return new self(sprintf('Identity User [%s] not found.', $userId));
    }

    public function errorCode(): string
    {
        return 'identity_user_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
