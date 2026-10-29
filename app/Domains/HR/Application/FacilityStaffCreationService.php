<?php

declare(strict_types=1);

namespace App\Domains\HR\Application;

use App\Domains\Authentication\Contracts\FacilityUserInvitationApi;
use App\Domains\HR\Application\Command\CreateEmployment;
use App\Domains\HR\Application\Command\CreatePerson;
use App\Domains\HR\Application\Command\CreateStaff;
use App\Domains\HR\Application\Command\LinkStaffToUser;
use App\Domains\HR\Domain\ValueObject\AssignmentScope;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\CommandBus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class FacilityStaffCreationService
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly FacilityAssignmentRequestService $assignmentRequests,
        private readonly FacilityUserInvitationApi $invitations,
        private readonly AuditLogger $audit,
        private readonly ScopeChecker $scopeChecker,
    ) {}

    /**
     * @param  array{
     *     tenantId: string,
     *     facilityId: string,
     *     actor: User,
     *     personId: string,
     *     staffId: string,
     *     requestId: string,
     *     firstName: string,
     *     middleName: string|null,
     *     lastName: string,
     *     dateOfBirth: string,
     *     gender: string,
     *     phone: string|null,
     *     email: string|null,
     *     photoDocumentId: string|null,
     *     departmentId: string|null,
     *     unitId: string|null,
     *     scope: AssignmentScope,
     *     effectiveFrom: string,
     *     position: array{id: string, cadreId: string}|null,
     *     contractType: string|null,
     *     employmentStartDate: string|null,
     *     fte: string,
     *     requestLogin: bool,
     *     primaryRoleKey: string|null,
     *     secondaryRoleKeys: list<string>,
     *     changeReference: string|null,
     *     accessReason: string|null
     * }  $input
     */
    public function create(array $input): void
    {
        DB::transaction(function () use ($input): void {
            $this->commandBus->dispatch(new CreatePerson(
                firstName: $input['firstName'],
                middleName: $input['middleName'],
                lastName: $input['lastName'],
                dateOfBirth: $input['dateOfBirth'],
                gender: $input['gender'],
                personId: $input['personId'],
                phone: $input['phone'],
                email: $input['email'],
                photoDocumentId: $input['photoDocumentId'],
            ));
            $this->commandBus->dispatch(new CreateStaff(
                personId: $input['personId'],
                staffId: $input['staffId'],
                primaryCadreId: $input['position']['cadreId'] ?? null,
                primaryPositionId: $input['position']['id'] ?? null,
            ));

            if ($input['position'] !== null) {
                abort_unless($input['contractType'] !== null && $input['employmentStartDate'] !== null, 422);
                $this->commandBus->dispatch(new CreateEmployment(
                    staffId: $input['staffId'],
                    positionId: $input['position']['id'],
                    contractType: $input['contractType'],
                    startDate: $input['employmentStartDate'],
                    fte: $input['fte'],
                ));
            }

            $this->assignmentRequests->create(
                requestId: $input['requestId'],
                tenantId: $input['tenantId'],
                staffId: $input['staffId'],
                facilityId: $input['facilityId'],
                departmentId: $input['departmentId'],
                unitId: $input['unitId'],
                scope: $input['scope'],
                effectiveFrom: $input['effectiveFrom'],
                requesterId: (string) $input['actor']->getKey(),
            );

            if ($input['requestLogin']) {
                abort_unless(
                    is_string($input['primaryRoleKey'])
                        && is_string($input['email'])
                        && is_string($input['changeReference'])
                        && is_string($input['accessReason']),
                    422,
                );
                $roleKeys = [
                    $input['primaryRoleKey'],
                    ...$input['secondaryRoleKeys'],
                ];
                try {
                    $invitation = $this->invitations->inviteFacilityUser(
                        tenantId: $input['tenantId'],
                        roleKey: $roleKeys,
                        facilityId: $input['facilityId'],
                        name: trim(implode(' ', array_filter([
                            $input['firstName'],
                            $input['middleName'],
                            $input['lastName'],
                        ]))),
                        email: mb_strtolower(trim((string) $input['email'])),
                        changeReference: (string) $input['changeReference'],
                        reason: trim((string) $input['accessReason']),
                        actor: $input['actor'],
                        scopeChecker: $this->scopeChecker,
                        staffId: $input['staffId'],
                    );
                } catch (RuntimeException $exception) {
                    Log::notice('HR staff account invitation was rejected.', [
                        'actor_id' => (string) $input['actor']->getKey(),
                        'tenant_id' => $input['tenantId'],
                        'facility_id' => $input['facilityId'],
                        'roles' => $roleKeys,
                        'reason' => $exception->getMessage(),
                    ]);

                    throw ValidationException::withMessages(['facility_role' => $exception->getMessage()]);
                }

                $this->commandBus->dispatch(new LinkStaffToUser(
                    staffId: $input['staffId'],
                    userId: $invitation['userId'],
                    linkedBy: (string) $input['actor']->getKey(),
                ));
            }

            $this->audit->recordFor(
                tenantId: $input['tenantId'],
                action: 'hr.staff_assignment.requested',
                actorId: (string) $input['actor']->getKey(),
                facilityId: $input['facilityId'],
                metadata: [
                    'request' => $input['requestId'],
                    'staff' => $input['staffId'],
                    'scope' => $input['scope']->value,
                ],
                targetType: 'staff',
                targetId: $input['staffId'],
            );
        });
    }

    /**
     * @param  list<string>  $roleKeys
     * @param  array{id: string, firstName: string, middleName: string|null, lastName: string}  $staff
     */
    public function inviteAndLinkStaffAccount(
        string $tenantId,
        string $facilityId,
        User $actor,
        array $roleKeys,
        array $staff,
        string $email,
        string $changeReference,
        string $reason,
    ): void {
        try {
            DB::transaction(function () use (
                $tenantId,
                $facilityId,
                $actor,
                $roleKeys,
                $staff,
                $email,
                $changeReference,
                $reason,
            ): void {
                $invitation = $this->invitations->inviteFacilityUser(
                    tenantId: $tenantId,
                    roleKey: $roleKeys,
                    facilityId: $facilityId,
                    name: trim(implode(' ', array_filter([
                        $staff['firstName'],
                        $staff['middleName'],
                        $staff['lastName'],
                    ]))),
                    email: mb_strtolower(trim($email)),
                    changeReference: $changeReference,
                    reason: trim($reason),
                    actor: $actor,
                    scopeChecker: $this->scopeChecker,
                    staffId: $staff['id'],
                );
                $this->commandBus->dispatch(new LinkStaffToUser(
                    staffId: $staff['id'],
                    userId: $invitation['userId'],
                    linkedBy: (string) $actor->getKey(),
                ));
            });
        } catch (RuntimeException $exception) {
            Log::notice('HR staff account invitation was rejected.', [
                'actor_id' => (string) $actor->getKey(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'staff_id' => $staff['id'],
                'roles' => $roleKeys,
                'reason' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }
    }
}
