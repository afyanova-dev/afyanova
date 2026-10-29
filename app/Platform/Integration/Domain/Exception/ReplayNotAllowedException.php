<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Exception;

use RuntimeException;

final class ReplayNotAllowedException extends RuntimeException
{
    public static function forStatus(string $messageId, string $status): self
    {
        return new self("Cannot replay message '{$messageId}': current status '{$status}' is not eligible for replay (only DEAD_LETTER or REJECTED).");
    }
}
