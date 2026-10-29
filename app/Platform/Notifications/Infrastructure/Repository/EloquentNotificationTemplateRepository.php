<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Repository;

use App\Platform\Notifications\Domain\Model\NotificationTemplate;
use App\Platform\Notifications\Domain\Repository\NotificationTemplateRepositoryInterface;

final class EloquentNotificationTemplateRepository implements NotificationTemplateRepositoryInterface
{
    public function findByKey(string $key, ?int $version = null): ?NotificationTemplate
    {
        $query = NotificationTemplate::query()->where('key', $key);

        if ($version !== null) {
            $query->where('version', $version);
        } else {
            $query->orderBy('version', 'desc');
        }

        /** @var NotificationTemplate|null */
        return $query->first();
    }

    public function save(NotificationTemplate $template): void
    {
        $template->save();
    }
}
