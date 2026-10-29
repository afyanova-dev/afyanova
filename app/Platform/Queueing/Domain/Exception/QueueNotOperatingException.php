<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Exception;

use RuntimeException;

final class QueueNotOperatingException extends RuntimeException
{
    public static function paused(string $queueName, ?string $reason): self
    {
        $details = $reason !== null ? " (Reason: {$reason})" : '';

        return new self("Queue '{$queueName}' is currently PAUSED{$details}.");
    }

    public static function closed(string $queueName): self
    {
        return new self("Queue '{$queueName}' is CLOSED for operations.");
    }

    public static function inactive(string $queueName): self
    {
        return new self("Queue '{$queueName}' is not active.");
    }
}
