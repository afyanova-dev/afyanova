<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Authorization;

use App\Domains\Theatre\Application\Command\AmendAnaesthesiaRecord;
use App\Domains\Theatre\Application\Command\RecordAnaesthesia;
use App\Domains\Theatre\Application\Command\SignAnaesthesiaRecord;
use App\Domains\Theatre\Application\Command\UpdateAnaesthesiaContent;
use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * AnaesthesiaRecord aggregate policy (ADR-155 D-H). Two keys back three
 * abilities:
 *   theatre.anaesthesia.record → create, record (content edit)
 *   theatre.anaesthesia.sign   → sign, amend
 */
final class AnaesthesiaRecordPolicy extends BasePolicy
{
    /**
     * `RecordAnaesthesia` carries no `facilityId` — it comes from the
     * SurgicalCase, unknown until the handler loads it. Coarse, any-facility
     * check only; the handler's own `assertActorScopedToCaseFacility` (the
     * T.2 precedent) enforces the real per-case-facility scope.
     */
    public function create(User $user, ?RecordAnaesthesia $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.anaesthesia.record');
    }

    public function record(User $user, AnaesthesiaRecord $record, ?UpdateAnaesthesiaContent $command = null): bool
    {
        return $this->hasRecordScope($user, $record, 'theatre.anaesthesia.record');
    }

    public function sign(User $user, AnaesthesiaRecord $record, SignAnaesthesiaRecord|AmendAnaesthesiaRecord|null $command = null): bool
    {
        return $this->hasRecordScope($user, $record, 'theatre.anaesthesia.sign');
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'theatre.access');
    }

    private function hasRecordScope(User $user, AnaesthesiaRecord $record, string $permissionKey): bool
    {
        return $this->sameTenant($user, (string) $record->tenant_id)
            && in_array(
                $permissionKey,
                $this->scope->effectivePermissions($user, (string) $record->facility_id),
                true,
            );
    }
}
