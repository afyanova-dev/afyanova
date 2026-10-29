<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Exception;

use RuntimeException;

final class ConnectorNotFoundException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("Integration connector with key '{$key}' not found.");
    }

    public static function forId(string $id): self
    {
        return new self("Integration connector with ID '{$id}' not found.");
    }
}
