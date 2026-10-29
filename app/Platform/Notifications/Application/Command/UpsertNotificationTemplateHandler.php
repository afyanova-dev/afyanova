<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Command;

use App\Platform\Notifications\Contracts\DTO\NotificationTemplateDto;
use App\Platform\Notifications\Domain\Model\NotificationCategory;
use App\Platform\Notifications\Domain\Model\NotificationTemplate;
use App\Platform\Notifications\Domain\Repository\NotificationTemplateRepositoryInterface;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;

final readonly class UpsertNotificationTemplateHandler
{
    public function __construct(
        private Container $container,
        private NotificationTemplateRepositoryInterface $templateRepository,
    ) {}

    public function __invoke(UpsertNotificationTemplateCommand $command): NotificationTemplateDto
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = $this->container->make(TenantContext::class);
        $tenantId = $tenantContext->tenantId();

        $category = NotificationCategory::from($command->category);
        $existing = $this->templateRepository->findByKey($command->key, $command->version);

        if ($existing !== null) {
            $existing->name = $command->name;
            $existing->category = $category;
            $existing->channels = $command->channels;
            $existing->content = $command->content;
            $existing->clinical_safe = $command->clinicalSafe;
            $existing->consent_required = $command->consentRequired;
            $existing->is_active = $command->isActive;

            $this->templateRepository->save($existing);

            return $this->toDto($existing);
        }

        $template = new NotificationTemplate([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'key' => $command->key,
            'name' => $command->name,
            'category' => $category,
            'channels' => $command->channels,
            'content' => $command->content,
            'clinical_safe' => $command->clinicalSafe,
            'consent_required' => $command->consentRequired,
            'version' => $command->version ?? 1,
            'is_active' => $command->isActive,
        ]);

        $this->templateRepository->save($template);

        return $this->toDto($template);
    }

    private function toDto(NotificationTemplate $t): NotificationTemplateDto
    {
        return new NotificationTemplateDto(
            id: (string) $t->id,
            key: $t->key,
            name: $t->name,
            category: $t->category->value,
            channels: array_values($t->channels),
            content: $t->content,
            clinicalSafe: $t->clinical_safe,
            consentRequired: $t->consent_required,
            version: $t->version,
            isActive: $t->is_active,
        );
    }
}
