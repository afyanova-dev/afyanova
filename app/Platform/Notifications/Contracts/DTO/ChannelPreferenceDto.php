<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Contracts\DTO;

final readonly class ChannelPreferenceDto
{
    /**
     * @param  list<string>  $mutedCategories
     */
    public function __construct(
        public string $id,
        public string $subjectType,
        public string $subjectId,
        public ?string $preferredChannel,
        public array $mutedCategories,
        public ?string $quietHoursStart,
        public ?string $quietHoursEnd,
        public string $preferredLanguage,
    ) {}
}
