<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\Exception\StaffUserLinkNotFoundException;
use App\Domains\HR\Domain\StaffUserLinkRepository;
use Illuminate\Support\Facades\Auth;

/**
 * Handles UnlinkStaffFromUser command (25-hr-and-workforce-domain.md §2, §7; ADR-206).
 *
 * Invariant 7.3: Unlinking never deletes either Staff or User.
 */
final class UnlinkStaffFromUserHandler
{
    public function __construct(
        private readonly StaffUserLinkRepository $links,
    ) {}

    public function __invoke(UnlinkStaffFromUser $command): void
    {
        $link = $this->links->getForStaffForUpdate($command->staffId);

        if ($link === null) {
            throw StaffUserLinkNotFoundException::forStaff($command->staffId);
        }

        $unlinkedBy = $command->unlinkedBy ?? (string) Auth::id();

        $link->recordUnlink($unlinkedBy, $command->reason);

        $this->links->delete($link);
    }
}
