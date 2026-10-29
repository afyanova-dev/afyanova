<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Exception;

use RuntimeException;

final class ConnectorDisabledException extends RuntimeException
{
    public static function forKey(string $key, string $status): self
    {
        return new self("Integration connector '{$key}' is not operable (current status: {$status}).");
    }
}
