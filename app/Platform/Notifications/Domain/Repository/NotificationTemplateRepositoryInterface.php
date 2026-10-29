<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Repository;

use App\Platform\Notifications\Domain\Model\NotificationTemplate;

interface NotificationTemplateRepositoryInterface
{
    public function findByKey(string $key, ?int $version = null): ?NotificationTemplate;

    public function save(NotificationTemplate $template): void;
}
