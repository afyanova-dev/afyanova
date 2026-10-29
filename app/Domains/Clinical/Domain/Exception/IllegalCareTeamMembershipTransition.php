<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A C.6 command was dispatched against a `CareTeamMembership` it cannot legally
 * transition (12-clinical-domain.md §4.1; ADR-142 D-I / D-J — the
 * `IllegalProblemStatusTransition` / `IllegalAllergyStatusTransition`
 * precedent). Validated in the aggregate before any mutation. Maps to HTTP 409
 * (IC §15).
 *
 *   - `endTerminal`                   — `EndCareTeamMembership` against an
 *                                       already-ENDED membership (`active_to` is
 *                                       set). ENDED is terminal — there is no
 *                                       reactivation; re-add the person with
 *                                       `AddCareTeamMember` (ADR-142 D-I).
 *   - `providerOfRecordRequiresActive` — a provider-of-record designation was
 *                                       attempted on an ENDED membership
 *                                       (ADR-142 D-G — the provider-of-record
 *                                       MUST be an ACTIVE care-team member).
 */
final class IllegalCareTeamMembershipTransition extends DomainException
{
    public static function endTerminal(): self
    {
        return new self(
            'EndCareTeamMembership is not legal against an already-ended membership '.
            '(ADR-142 D-I — ENDED is terminal, no reactivation).'
        );
    }

    public static function providerOfRecordRequiresActive(): self
    {
        return new self(
            'A provider-of-record designation requires an ACTIVE care-team membership '.
            '(ADR-142 D-G).'
        );
    }

    public function errorCode(): string
    {
        return 'illegal_care_team_membership_transition';
    }
}
