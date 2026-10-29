<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

/**
 * Cross-module gate for linked staff lifecycle access.
 *
 * Authentication and other modules must use this contract rather than the
 * Identity application service directly (IC §2.1).
 */
interface LinkedStaffAccessGate
{
    public function isBlockedByHrLifecycle(string $tenantId, string $userId): bool;

    public function isPendingOnboarding(string $tenantId, string $userId): bool;

    public function linkedStaffStatus(string $tenantId, string $userId): ?string;
}
