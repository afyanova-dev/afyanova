<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum CredentialStatus: string
{
    case ACTIVE = 'ACTIVE';
    case ROTATING = 'ROTATING';
    case REVOKED = 'REVOKED';
    case EXPIRED = 'EXPIRED';

    public function isUsable(): bool
    {
        return $this === self::ACTIVE || $this === self::ROTATING;
    }
}
