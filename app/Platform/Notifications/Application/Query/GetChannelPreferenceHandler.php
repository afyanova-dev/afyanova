<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Query;

use App\Platform\Notifications\Contracts\DTO\ChannelPreferenceDto;
use App\Platform\Notifications\Domain\Model\RecipientType;
use App\Platform\Notifications\Domain\Repository\ChannelPreferenceRepositoryInterface;

final readonly class GetChannelPreferenceHandler
{
    public function __construct(private ChannelPreferenceRepositoryInterface $preferenceRepository) {}

    public function __invoke(GetChannelPreferenceQuery $query): ?ChannelPreferenceDto
    {
        $type = RecipientType::from($query->subjectType);
        $preference = $this->preferenceRepository->findBySubject($type, $query->subjectId);
        if ($preference === null) {
            return null;
        }

        return new ChannelPreferenceDto(
            id: (string) $preference->id,
            subjectType: $preference->subject_type->value,
            subjectId: (string) $preference->subject_id,
            preferredChannel: $preference->preferred_channel?->value,
            mutedCategories: array_values($preference->muted_categories),
            quietHoursStart: $preference->quiet_hours_start,
            quietHoursEnd: $preference->quiet_hours_end,
            preferredLanguage: $preference->preferred_language,
        );
    }
}
