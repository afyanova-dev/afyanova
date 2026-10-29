<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\Exception\IdentityUserNotFoundException;
use App\Domains\HR\Domain\Exception\StaffAlreadyLinkedException;
use App\Domains\HR\Domain\Exception\UserAlreadyLinkedException;
use App\Domains\HR\Domain\Model\StaffUserLink;
use App\Domains\HR\Domain\StaffRepository;
use App\Domains\HR\Domain\StaffUserLinkRepository;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Handles LinkStaffToUser command (25-hr-and-workforce-domain.md §2, §7; ADR-206).
 *
 * Invariant 7.3: Optional link; 1:1 mapping between Staff and Identity.User.
 */
final class LinkStaffToUserHandler
{
    public function __construct(
        private readonly StaffUserLinkRepository $links,
        private readonly StaffRepository $staff,
        private readonly IdentityApi $identity,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(LinkStaffToUser $command): void
    {
        $this->staff->getById($command->staffId);

        if (! $this->identity->userExists($command->userId)) {
            throw IdentityUserNotFoundException::forId($command->userId);
        }

        if ($this->links->existsForStaff($command->staffId)) {
            $existing = $this->links->getForStaff($command->staffId);
            throw StaffAlreadyLinkedException::forStaff($command->staffId, $existing ? $existing->user_id : 'unknown');
        }

        if ($this->links->existsForUser($command->userId)) {
            $existing = $this->links->getForUser($command->userId);
            throw UserAlreadyLinkedException::forUser($command->userId, $existing ? $existing->staff_id : 'unknown');
        }

        $linkedBy = $command->linkedBy ?? (string) Auth::id();

        $link = StaffUserLink::create(
            tenantId: $this->context->tenantId(),
            staffId: $command->staffId,
            userId: $command->userId,
            linkedBy: $linkedBy,
            linkId: $command->linkId,
        );

        $this->links->save($link);
    }
}
