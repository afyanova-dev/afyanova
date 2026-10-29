<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Query;

final readonly class GetChannelPreferenceQuery
{
    public function __construct(
        public string $subjectType,
        public string $subjectId,
    ) {}
}
