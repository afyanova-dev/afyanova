<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Set or clear the single-actor approval limit for one scope (Shape B;
 * 19-procurement-domain.md §45, §228).
 *
 * A scope is a FACILITY, or a DEPARTMENT inside it (`$departmentRef` — an
 * Organization department id, the same value a requisition carries as
 * `requesting_department_ref`). `$limit === null` CLEARS the scope, so it falls
 * back to the facility row and then the facility-type default; `$limit === '0'`
 * is different — it pins the scope to "never one person".
 *
 * Authorization is per facility (`ApprovalPolicyPolicy::manage`), which is what
 * lets a Tenant Admin administer every facility while a Facility Admin only
 * administers their own.
 */
final readonly class SetApprovalLimit implements AuthorizesAgainst
{
    public function __construct(
        public string $facilityId,
        public ?string $departmentRef,
        public ?string $limit,
        public string $actorId,
    ) {
        foreach (['facilityId' => $facilityId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("SetApprovalLimit::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($departmentRef !== null && ! Str::isUuid($departmentRef)) {
            throw new InvalidArgumentException('SetApprovalLimit::$departmentRef must be a UUID or null.');
        }

        if ($limit !== null && (! is_numeric($limit) || (float) $limit < 0)) {
            throw new InvalidArgumentException('SetApprovalLimit::$limit must be a non-negative amount or null.');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
