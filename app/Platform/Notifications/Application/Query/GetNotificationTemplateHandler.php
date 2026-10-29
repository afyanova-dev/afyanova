<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Query;

use App\Platform\Notifications\Contracts\DTO\NotificationTemplateDto;
use App\Platform\Notifications\Domain\Repository\NotificationTemplateRepositoryInterface;

final readonly class GetNotificationTemplateHandler
{
    public function __construct(private NotificationTemplateRepositoryInterface $templateRepository) {}

    public function __invoke(GetNotificationTemplateQuery $query): ?NotificationTemplateDto
    {
        $template = $this->templateRepository->findByKey($query->key, $query->version);
        if ($template === null) {
            return null;
        }

        return new NotificationTemplateDto(
            id: (string) $template->id,
            key: $template->key,
            name: $template->name,
            category: $template->category->value,
            channels: array_values($template->channels),
            content: $template->content,
            clinicalSafe: $template->clinical_safe,
            consentRequired: $template->consent_required,
            version: $template->version,
            isActive: $template->is_active,
        );
    }
}
