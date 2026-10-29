<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Repository;

interface DeduplicationRepositoryInterface
{
    public function acquireLock(string $dedupeKey, string $requestId, int $ttlSeconds = 86400): bool;

    public function isDuplicate(string $dedupeKey): bool;
}
