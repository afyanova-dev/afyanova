<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AddCareTeamMember` named a `staff_user_id` that is not a staff identity
 * (12-clinical-domain.md §4.1; ADR-131, ADR-142 D-E). The handler validates the
 * participant with `IdentityApi::isStaff()` at add time only — a non-staff user
 * cannot be placed on a care team. Maps to HTTP 409 (IC §15).
 *
 * C.6 does NOT re-validate staff status on `EndCareTeamMembership` or
 * `ChangeProviderOfRecord`, and does NOT react to a later loss of staff status
 * (deferred to the HR & Workforce increment — PHASE 11; ADR-142 D-E).
 */
final class CareTeamMemberNotStaff extends DomainException
{
    public static function forUser(string $userId): self
    {
        return new self(sprintf(
            'User [%s] is not a staff identity and cannot be added to a care team.',
            $userId,
        ));
    }

    public function errorCode(): string
    {
        return 'care_team_member_not_staff';
    }
}
