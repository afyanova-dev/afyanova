<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Application\Command;

use App\Platform\Notifications\Domain\Model\ChannelPreference;
use App\Platform\Notifications\Domain\Model\NotificationChannel;
use App\Platform\Notifications\Domain\Model\RecipientType;
use App\Platform\Notifications\Domain\Repository\ChannelPreferenceRepositoryInterface;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;

final readonly class SetChannelPreferenceHandler
{
    public function __construct(
        private Container $container,
        private ChannelPreferenceRepositoryInterface $preferenceRepository,
    ) {}

    public function __invoke(SetChannelPreferenceCommand $command): void
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = $this->container->make(TenantContext::class);
        $tenantId = $tenantContext->tenantId();

        $type = RecipientType::from($command->subjectType);
        $preference = $this->preferenceRepository->findBySubject($type, $command->subjectId);

        if ($preference === null) {
            $preference = new ChannelPreference([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'subject_type' => $type,
                'subject_id' => $command->subjectId,
            ]);
        }

        $preference->preferred_channel = $command->preferredChannel !== null
            ? NotificationChannel::from($command->preferredChannel)
            : null;
        $preference->muted_categories = $command->mutedCategories;
        $preference->quiet_hours_start = $command->quietHoursStart;
        $preference->quiet_hours_end = $command->quietHoursEnd;
        $preference->preferred_language = $command->preferredLanguage;

        $this->preferenceRepository->save($preference);
    }
}
