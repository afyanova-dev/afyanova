<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

/**
 * The result of a successful `AddCareTeamMember` (the `AddProblemResult` /
 * `RecordAllergyResult` precedent) — the client-minted membership id, echoed
 * back for confirmation.
 */
final readonly class AddCareTeamMemberResult
{
    public function __construct(
        public string $careTeamMembershipId,
    ) {}
}
