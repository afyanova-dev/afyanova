<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Contracts\DTO;

final readonly class NotificationTemplateDto
{
    /**
     * @param  list<string>  $channels
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        public string $id,
        public string $key,
        public string $name,
        public string $category,
        public array $channels,
        public array $content,
        public bool $clinicalSafe,
        public bool $consentRequired,
        public int $version,
        public bool $isActive,
    ) {}
}
