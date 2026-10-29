<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Repository;

use App\Platform\Notifications\Domain\Model\ChannelPreference;
use App\Platform\Notifications\Domain\Model\RecipientType;

interface ChannelPreferenceRepositoryInterface
{
    public function findBySubject(RecipientType $type, string $id): ?ChannelPreference;

    public function save(ChannelPreference $preference): void;
}
