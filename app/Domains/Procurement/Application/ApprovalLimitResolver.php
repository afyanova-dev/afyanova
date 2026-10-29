<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Procurement\Domain\ApprovalPolicyRepository;
use App\Domains\Procurement\Domain\Money;

/**
 * Works out the single-actor approval limit that applies to one order.
 *
 * The limit is an operational policy, so it is NOT a deployment setting. A
 * dispensary, a health centre and a hospital buy at completely different
 * scales, and within a hospital the laboratory's reagent order is not the
 * pharmacy's drug order. The resolution order therefore is:
 *
 *   1. the DEPARTMENT row  (facility + department)
 *   2. the FACILITY row    (facility, department = NULL)
 *   3. the facility-type default from `config('procurement.approval.…')` —
 *      only a day-one proposal, so a fresh facility behaves sensibly
 *   4. `0` — off, which leaves the different-actor guards untouched
 *
 * `0` always means "no single-actor approval", never "unlimited".
 */
final class ApprovalLimitResolver
{
    public function __construct(
        private readonly ApprovalPolicyRepository $policies,
        private readonly OrganizationApi $organization,
    ) {}

    /**
     * The effective limit for an order raised at `$facilityId`, optionally for
     * a department (`$departmentRef` — a requisition's
     * `requesting_department_ref`, opaque to this module).
     */
    public function resolve(string $facilityId, ?string $departmentRef): string
    {
        $departmentRef = $departmentRef === null || trim($departmentRef) === ''
            ? null
            : trim($departmentRef);

        if ($departmentRef !== null) {
            $departmentPolicy = $this->policies->findFor($facilityId, $departmentRef);

            if ($departmentPolicy !== null) {
                return Money::of((string) $departmentPolicy->self_approval_limit);
            }
        }

        $facilityPolicy = $this->policies->findFor($facilityId, null);

        if ($facilityPolicy !== null) {
            return Money::of((string) $facilityPolicy->self_approval_limit);
        }

        return $this->facilityTypeDefault($facilityId);
    }

    private function facilityTypeDefault(string $facilityId): string
    {
        $defaults = config('procurement.approval.facility_type_defaults');
        $facilityType = $this->organization->facilityType($facilityId);

        if (! is_array($defaults) || $facilityType === null) {
            return '0';
        }

        $value = $defaults[$facilityType] ?? null;

        if (! is_string($value) && ! is_numeric($value)) {
            return '0';
        }

        return Money::of((string) $value);
    }
}
