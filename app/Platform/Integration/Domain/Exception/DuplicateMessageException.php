<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Exception;

use RuntimeException;

final class DuplicateMessageException extends RuntimeException
{
    public static function forRef(string $connectorKey, string $externalRef): self
    {
        return new self("Duplicate inbound message suppressed for connector '{$connectorKey}' and external reference '{$externalRef}'.");
    }
}
