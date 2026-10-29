<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Exception;

use DomainException;

final class DuplicateNotificationException extends DomainException
{
    public static function forKey(string $dedupeKey): self
    {
        return new self("A notification with deduplication key [{$dedupeKey}] has already been accepted.");
    }
}
