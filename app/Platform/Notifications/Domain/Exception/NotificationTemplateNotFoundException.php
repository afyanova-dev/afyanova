<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Exception;

use App\Shared\Domain\NotFound;

final class NotificationTemplateNotFoundException extends NotFound
{
    public static function forKey(string $key, ?int $version = null): self
    {
        $vStr = $version !== null ? " version [{$version}]" : '';

        return new self("Notification template [{$key}]{$vStr} not found in current tenant scope.");
    }
}
