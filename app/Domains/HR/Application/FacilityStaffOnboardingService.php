<?php

declare(strict_types=1);

namespace App\Domains\HR\Application;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Domains\HR\Infrastructure\Reader\FacilityStaffOnboardingReader;

final class FacilityStaffOnboardingService
{
    public function __construct(private readonly FacilityStaffOnboardingReader $reader) {}

    /**
     * @param  list<string>  $roleKeys
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     summary: array{
     *         total: int,
     *         needsAction: int,
     *         placements: int,
     *         invitations: int,
     *         expiredInvitations: int,
     *         access: int,
     *         readyToOnboard: int
     *     }
     * }
     */
    public function worklist(
        string $tenantId,
        string $facilityId,
        string $today,
        string $actorId,
        array $roleKeys,
        bool $canReviewPlacements,
        bool $canManagePlacement,
        bool $canManageStaff,
        bool $canManageEmployment,
        bool $canManageAccess,
    ): array {
        $rawRows = $this->reader->queue(
            tenantId: $tenantId,
            facilityId: $facilityId,
            today: $today,
            actorId: $actorId,
            roleKeys: $roleKeys,
            canManageAccess: $canManageAccess,
        );

        $rows = [];
        $summary = [
            'total' => 0,
            'needsAction' => 0,
            'placements' => 0,
            'invitations' => 0,
            'expiredInvitations' => 0,
            'access' => 0,
            'readyToOnboard' => 0,
        ];

        foreach ($rawRows as $rawRow) {
            $nextAction = $this->nextAction(
                row: $rawRow,
                canReviewPlacements: $canReviewPlacements,
                canManagePlacement: $canManagePlacement,
                canManageStaff: $canManageStaff,
                canManageEmployment: $canManageEmployment,
                canManageAccess: $canManageAccess,
            );
            $needsAction = ! in_array($nextAction['kind'], [
                'WAIT_ACCOUNT',
                'WAIT_PLACEMENT',
                'VIEW_PROFILE',
            ], true);
            $accountState = (string) $rawRow['accountState'];
            $placementState = (string) $rawRow['placement']['state'];
            $staffStatus = (string) $rawRow['staffStatus'];
            $pendingAccess = (int) $rawRow['access']['pendingCount'];

            $rows[] = [
                ...$rawRow,
                'placement' => [
                    ...$rawRow['placement'],
                    'label' => $this->placementLabel($placementState),
                ],
                'account' => [
                    'state' => $accountState,
                    'label' => $this->accountLabel($accountState),
                    'email' => $rawRow['accountEmail'],
                    'expiresAt' => $rawRow['invitation']['expiresAt'],
                    'invitationStatus' => $rawRow['invitation']['status'],
                ],
                'access' => [
                    ...$rawRow['access'],
                    'label' => $this->accessLabel((string) $rawRow['access']['state'], $pendingAccess),
                    'roleLabels' => array_map(
                        static fn (string $roleKey): string => FacilityUserRoleCatalog::label($roleKey) ?? $roleKey,
                        $rawRow['access']['roleKeys'],
                    ),
                ],
                'employment' => [
                    ...$rawRow['employment'],
                    'label' => $this->employmentLabel($rawRow['employment']),
                ],
                'onboarding' => [
                    'state' => $this->onboardingState($staffStatus, $placementState, $accountState),
                    'label' => $this->onboardingLabel($staffStatus, $placementState, $accountState),
                ],
                'nextAction' => $nextAction,
                'needsAction' => $needsAction,
            ];

            $summary['total']++;
            if ($needsAction) {
                $summary['needsAction']++;
            }
            if ($placementState === 'PENDING') {
                $summary['placements']++;
            }
            if (in_array($accountState, [
                'INVITED',
                'INVITATION_EXPIRED',
                'INVITATION_REVOKED',
                'INVITATION_REPLACED',
                'SETUP_PENDING',
            ], true)) {
                $summary['invitations']++;
            }
            if ($accountState === 'INVITATION_EXPIRED') {
                $summary['expiredInvitations']++;
            }
            if ($pendingAccess > 0) {
                $summary['access']++;
            }
            if ($staffStatus === 'PROSPECT' && $placementState === 'CONFIRMED') {
                $summary['readyToOnboard']++;
            }
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{kind: string, label: string}
     */
    private function nextAction(
        array $row,
        bool $canReviewPlacements,
        bool $canManagePlacement,
        bool $canManageStaff,
        bool $canManageEmployment,
        bool $canManageAccess,
    ): array {
        $placementState = (string) $row['placement']['state'];
        $accountState = (string) $row['accountState'];
        $staffStatus = (string) $row['staffStatus'];
        $pendingAccess = (int) $row['access']['pendingCount'];
        $employmentState = (string) $row['employment']['state'];

        if ($canReviewPlacements && $placementState === 'PENDING') {
            return ['kind' => 'CONFIRM_PLACEMENT', 'label' => 'Confirm placement'];
        }
        if ($canManagePlacement && $placementState === 'REJECTED') {
            return ['kind' => 'UPDATE_PLACEMENT', 'label' => 'Update placement'];
        }
        if ($canManageAccess && $accountState === 'INVITATION_EXPIRED') {
            return ['kind' => 'RESEND_INVITATION', 'label' => 'Resend invitation'];
        }
        if ($canManageAccess && in_array($accountState, [
            'INVITATION_REVOKED',
            'INVITATION_REPLACED',
        ], true)) {
            return ['kind' => 'REVIEW_INVITATION', 'label' => 'Review invitation'];
        }
        if ($canManageAccess && $accountState === 'NO_ACCOUNT' && $placementState === 'CONFIRMED') {
            return ['kind' => 'INVITE_ACCOUNT', 'label' => 'Invite account'];
        }
        if ($canManageAccess && $pendingAccess > 0) {
            return ['kind' => 'APPROVE_ACCESS', 'label' => 'Approve access'];
        }
        if ($canManageStaff && $staffStatus === 'PROSPECT' && $placementState === 'CONFIRMED') {
            return ['kind' => 'COMPLETE_ONBOARDING', 'label' => 'Complete onboarding'];
        }
        if ($canManageEmployment && $staffStatus === 'ACTIVE' && $employmentState === 'DRAFT_DUE') {
            return ['kind' => 'START_EMPLOYMENT', 'label' => 'Start employment'];
        }
        if ($placementState === 'PENDING') {
            return ['kind' => 'WAIT_PLACEMENT', 'label' => 'Waiting for placement approval'];
        }
        if (in_array($accountState, ['INVITED', 'SETUP_PENDING'], true)) {
            return ['kind' => 'WAIT_ACCOUNT', 'label' => 'Waiting for account setup'];
        }

        return ['kind' => 'VIEW_PROFILE', 'label' => 'View profile'];
    }

    private function placementLabel(string $state): string
    {
        return match ($state) {
            'CONFIRMED' => 'Confirmed',
            'PENDING' => 'Pending confirmation',
            'REJECTED' => 'Rejected',
            default => 'No placement',
        };
    }

    private function accountLabel(string $state): string
    {
        return match ($state) {
            'NO_ACCOUNT' => 'No account',
            'INVITED' => 'Invitation pending',
            'INVITATION_EXPIRED' => 'Invitation expired',
            'INVITATION_REVOKED' => 'Invitation revoked',
            'INVITATION_REPLACED' => 'Invitation replaced',
            'SETUP_PENDING' => 'Setup not completed',
            'ACTIVE' => 'Active account',
            default => $state,
        };
    }

    private function accessLabel(string $state, int $pendingCount): string
    {
        if ($pendingCount > 0) {
            return $pendingCount === 1 ? '1 role pending' : "{$pendingCount} roles pending";
        }

        return match ($state) {
            'ACTIVE' => 'Access granted',
            'NONE' => 'No active role',
            default => 'Waiting for account',
        };
    }

    /** @param array{state: string, status: string|null, startDate: string|null} $employment */
    private function employmentLabel(array $employment): string
    {
        return match ($employment['state']) {
            'ACTIVE' => 'Employment active',
            'DRAFT_DUE' => 'Draft employment due',
            'DRAFT_FUTURE' => $employment['startDate'] === null
                ? 'Draft employment'
                : 'Starts '.$employment['startDate'],
            'SUSPENDED' => 'Employment suspended',
            'ENDED' => 'Employment ended',
            default => 'No employment',
        };
    }

    private function onboardingState(string $staffStatus, string $placementState, string $accountState): string
    {
        if ($staffStatus === 'ACTIVE') {
            return 'ACTIVE';
        }
        if ($staffStatus === 'PROSPECT' && $placementState !== 'CONFIRMED') {
            return 'BLOCKED';
        }
        if ($staffStatus === 'PROSPECT' && $accountState === 'NO_ACCOUNT') {
            return 'READY';
        }
        if ($staffStatus === 'PROSPECT') {
            return 'READY';
        }

        return 'IN_PROGRESS';
    }

    private function onboardingLabel(string $staffStatus, string $placementState, string $accountState): string
    {
        if ($staffStatus === 'ACTIVE') {
            return 'Active';
        }
        if ($staffStatus === 'PROSPECT' && $placementState !== 'CONFIRMED') {
            return 'Placement not confirmed';
        }
        if ($staffStatus === 'PROSPECT') {
            return $accountState === 'NO_ACCOUNT' ? 'Ready, no account' : 'Ready to onboard';
        }

        return 'Onboarding in progress';
    }
}
