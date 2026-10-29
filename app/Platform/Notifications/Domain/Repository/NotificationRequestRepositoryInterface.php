<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Repository;

use App\Platform\Notifications\Domain\Model\NotificationRequest;

interface NotificationRequestRepositoryInterface
{
    public function findById(string $id): ?NotificationRequest;

    public function findByDedupeKey(string $dedupeKey): ?NotificationRequest;

    public function save(NotificationRequest $request): void;
}
