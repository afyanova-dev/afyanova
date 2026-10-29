<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Contracts\DTO;

final readonly class SendBulkNotificationDto
{
    /**
     * @param  list<array{type: string, id: string}>  $recipients
     * @param  array<string, mixed>  $context
     * @param  list<string>|null  $channels
     */
    public function __construct(
        public string $templateKey,
        public array $recipients,
        public string $onBehalfOfDomain,
        public string $onBehalfOfEntity,
        public array $context = [],
        public ?array $channels = null,
        public string $priority = 'ROUTINE',
        public ?string $consentBasisRef = null,
    ) {}
}
