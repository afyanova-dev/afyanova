<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Repository;

use App\Platform\Integration\Domain\Model\WebhookEndpoint;
use App\Platform\Integration\Domain\Repository\WebhookEndpointRepository;

final class EloquentWebhookEndpointRepository implements WebhookEndpointRepository
{
    public function findByPath(string $pathSlug): ?WebhookEndpoint
    {
        return WebhookEndpoint::query()->where('path_slug', $pathSlug)->first();
    }

    public function save(WebhookEndpoint $endpoint): void
    {
        $endpoint->save();
    }
}
