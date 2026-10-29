<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Authorization;

use App\Domains\CareCoordination\Application\Command\DetachEncounterFromEpisode;
use App\Domains\CareCoordination\Application\Command\LinkEncounterToEpisode;
use App\Domains\CareCoordination\Domain\Model\CareEpisodeEncounter;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * CareEpisodeEncounter (episode ↔ encounter link) aggregate policy
 * (ADR-153 D-E/D-H). Same key as CareEpisode: `care_coordination.episode.manage`.
 */
final class CareEpisodeEncounterPolicy extends BasePolicy
{
    /**
     * `LinkEncounterToEpisode` carries no facility — it is taken from the
     * encounter context by the handler, which also runs the ADR-133
     * per-encounter-facility refinement. Coarse, any-facility check only.
     */
    public function create(User $user, ?LinkEncounterToEpisode $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'care_coordination.episode.manage');
    }

    public function manage(User $user, CareEpisodeEncounter $link, ?DetachEncounterFromEpisode $command = null): bool
    {
        return $this->sameTenant($user, (string) $link->tenant_id)
            && in_array(
                'care_coordination.episode.manage',
                $this->scope->effectivePermissions($user, (string) $link->facility_id),
                true,
            );
    }
}
