<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Command;

final readonly class SetChannelPreferenceCommand
{
    /**
     * @param  list<string>  $mutedCategories
     */
    public function __construct(
        public string $subjectType,
        public string $subjectId,
        public ?string $preferredChannel = null,
        public array $mutedCategories = [],
        public ?string $quietHoursStart = null,
        public ?string $quietHoursEnd = null,
        public string $preferredLanguage = 'sw',
    ) {}
}
