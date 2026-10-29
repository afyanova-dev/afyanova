<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Exception;

use RuntimeException;

final class DeadLetterException extends RuntimeException
{
    public static function forInbound(string $messageId, string $reason): self
    {
        return new self("Inbound message '{$messageId}' dead-lettered: {$reason}");
    }

    public static function forOutbound(string $messageId, string $reason): self
    {
        return new self("Outbound message '{$messageId}' dead-lettered: {$reason}");
    }
}
