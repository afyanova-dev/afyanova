<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Exception;

use RuntimeException;

final class SchedulableResourceNotFoundException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self("Schedulable resource with id [{$id}] not found.");
    }
}
