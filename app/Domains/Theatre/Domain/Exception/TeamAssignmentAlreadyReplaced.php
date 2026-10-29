<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `ReplaceTeamMember` named an assignment that was already replaced
 * (17-theatre-domain.md §45 "Staff Assignment History"; ADR-150 D-F). Maps
 * to HTTP 409.
 */
final class TeamAssignmentAlreadyReplaced extends DomainException
{
    public static function forAssignment(string $assignmentId): self
    {
        return new self(sprintf(
            'Team assignment [%s] was already replaced.',
            $assignmentId,
        ));
    }

    public function errorCode(): string
    {
        return 'team_assignment_already_replaced';
    }
}
