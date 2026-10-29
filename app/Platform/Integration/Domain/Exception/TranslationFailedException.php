<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Exception;

use RuntimeException;

final class TranslationFailedException extends RuntimeException
{
    public static function forPayload(string $connectorKey, string $reason): self
    {
        return new self("Payload translation failed for connector '{$connectorKey}': {$reason}");
    }
}
