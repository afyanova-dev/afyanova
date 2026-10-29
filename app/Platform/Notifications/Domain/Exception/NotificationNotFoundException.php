<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Exception;

use App\Shared\Domain\NotFound;

final class NotificationNotFoundException extends NotFound
{
    public static function forId(string $id): self
    {
        return new self("Notification request [{$id}] not found in current tenant scope.");
    }
}
