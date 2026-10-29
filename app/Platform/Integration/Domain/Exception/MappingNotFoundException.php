<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Exception;

use RuntimeException;

final class MappingNotFoundException extends RuntimeException
{
    public static function forExternal(string $connectorKey, string $externalId): self
    {
        return new self("No internal mapping found for connector '{$connectorKey}' and external ID '{$externalId}'.");
    }

    public static function forInternal(string $connectorKey, string $domain, string $internalId): self
    {
        return new self("No external mapping found for connector '{$connectorKey}', domain '{$domain}', and internal ID '{$internalId}'.");
    }
}
