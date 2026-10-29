<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Authorization;

use App\Domains\CareCoordination\Application\Command\CloseCareEpisode;
use App\Domains\CareCoordination\Application\Command\MarkEpisodeTransferredOut;
use App\Domains\CareCoordination\Application\Command\OpenCareEpisode;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * CareEpisode aggregate policy (ADR-153 D-E/D-H). One key backs both
 * abilities: `care_coordination.episode.manage`.
 */
final class CareEpisodePolicy extends BasePolicy
{
    /**
     * `OpenCareEpisode` carries no `facilityId` — it comes from the caller's
     * active facility context, resolved by the handler. Coarse, any-facility
     * check only.
     */
    public function create(User $user, ?OpenCareEpisode $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'care_coordination.episode.manage');
    }

    public function manage(User $user, CareEpisode $episode, CloseCareEpisode|MarkEpisodeTransferredOut|null $command = null): bool
    {
        return $this->sameTenant($user, (string) $episode->tenant_id)
            && in_array(
                'care_coordination.episode.manage',
                $this->scope->effectivePermissions($user, (string) $episode->facility_id),
                true,
            );
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'care_coordination.access');
    }
}
