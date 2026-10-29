<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Query;

final readonly class GetInAppNotificationsQuery
{
    public function __construct(
        public string $userId,
        public int $limit = 50,
    ) {}
}
