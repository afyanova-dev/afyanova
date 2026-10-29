<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Command;

final readonly class UpsertNotificationTemplateCommand
{
    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $category,
        public array $channels,
        public array $content,
        public bool $clinicalSafe = false,
        public bool $consentRequired = false,
        public ?int $version = null,
        public bool $isActive = true,
    ) {}
}
