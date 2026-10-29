<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Query;

final readonly class GetNotificationStatusQuery
{
    public function __construct(public string $requestId) {}
}
