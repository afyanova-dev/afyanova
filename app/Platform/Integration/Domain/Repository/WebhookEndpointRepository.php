<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Repository;

use App\Platform\Integration\Domain\Model\WebhookEndpoint;

interface WebhookEndpointRepository
{
    public function findByPath(string $pathSlug): ?WebhookEndpoint;

    public function save(WebhookEndpoint $endpoint): void;
}
