<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

use App\Shared\Audit\AuditLogger;

interface LinkedStaffLifecycleApi
{
    public function lockLinkedUserForLifecycle(string $tenantId, string $staffId): ?string;

    public function manageFacilityStaffAssignment(
        string $tenantId,
        string $staffId,
        string $assignmentId,
        string $facilityId,
        string $action,
        string $actorId,
        string $reason,
        AuditLogger $audit,
    ): void;

    /**
     * @return array{
     *     userId: string|null,
     *     suspensionId: string|null,
     *     assignments: list<string>,
     *     privilegedRequests: list<string>,
     *     sessionsRevoked: int
     * }
     */
    public function suspendForStaff(
        string $tenantId,
        string $staffId,
        string $actorId,
        ?string $reason,
        AuditLogger $audit,
    ): array;

    /** @return array{userId: string|null, assignments: list<string>} */
    public function restoreForStaff(
        string $tenantId,
        string $staffId,
        string $actorId,
        AuditLogger $audit,
    ): array;

    /**
     * @return array{
     *     userId: string|null,
     *     facilities: list<string>,
     *     assignments: list<string>,
     *     privilegedRequests: list<string>,
     *     sessionsRevoked: int
     * }
     */
    public function revokeAllAccessForStaff(
        string $tenantId,
        string $staffId,
        string $actorId,
        ?string $reason,
        AuditLogger $audit,
    ): array;
}
