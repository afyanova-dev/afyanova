<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Repository;

use App\Platform\Notifications\Domain\Model\ChannelPreference;
use App\Platform\Notifications\Domain\Model\RecipientType;
use App\Platform\Notifications\Domain\Repository\ChannelPreferenceRepositoryInterface;

final class EloquentChannelPreferenceRepository implements ChannelPreferenceRepositoryInterface
{
    public function findBySubject(RecipientType $type, string $id): ?ChannelPreference
    {
        /** @var ChannelPreference|null */
        return ChannelPreference::query()
            ->where('subject_type', $type->value)
            ->where('subject_id', $id)
            ->first();
    }

    public function save(ChannelPreference $preference): void
    {
        $preference->save();
    }
}
