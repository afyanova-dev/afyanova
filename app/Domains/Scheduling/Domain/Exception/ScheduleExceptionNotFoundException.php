<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use RuntimeException;

final class ScheduleExceptionNotFoundException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self("Schedule exception with id [{$id}] not found.");
    }
}
