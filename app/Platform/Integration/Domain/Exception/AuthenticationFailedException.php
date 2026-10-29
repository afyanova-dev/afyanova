<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Exception;

use RuntimeException;

final class AuthenticationFailedException extends RuntimeException
{
    public static function invalidSignature(string $connectorKey): self
    {
        return new self("Cryptographic authentication failed for connector '{$connectorKey}': invalid signature.");
    }

    public static function invalidToken(string $connectorKey): self
    {
        return new self("Authentication failed for connector '{$connectorKey}': invalid or expired credential.");
    }
}
