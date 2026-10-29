<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Authorization;

use App\Domains\Procurement\Application\Command\BlacklistSupplier;
use App\Domains\Procurement\Application\Command\ChangeSupplierStatus;
use App\Domains\Procurement\Application\Command\RegisterSupplier;
use App\Domains\Procurement\Application\Command\UpdateSupplier;
use App\Domains\Procurement\Application\Command\UpdateSupplierLicence;
use App\Domains\Procurement\Application\Query\GetSupplier;
use App\Domains\Procurement\Application\Query\GetSuppliers;
use App\Domains\Procurement\Application\RequisitionDepartmentScope;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;

/**
 * Supplier aggregate policy (ADR-171 D-D / D-M).
 *
 * ONE permission key backs every supplier write — `procurement.suppliers.manage`.
 * `register` is the class-level check for `RegisterSupplier` (the supplier does
 * not exist yet); `manage` is the per-subject check for update / status-change /
 * blacklist (a supplier is tenant-scoped, NOT facility-scoped — §105, so the
 * per-subject check is coarse tenant + key, no facility refinement). `view`
 * gates every `#[ProtectedQuery(subjectType: Supplier::class)]`.
 */
final class SupplierPolicy extends BasePolicy
{
    public function __construct(
        TenantContext $context,
        ScopeChecker $scope,
        private readonly RequisitionDepartmentScope $departmentScope,
    ) {
        parent::__construct($context, $scope);
    }

    public function register(User $user, ?RegisterSupplier $command = null): bool
    {
        return $this->coarse($user, 'procurement.suppliers.manage');
    }

    public function manage(
        User $user,
        Supplier $supplier,
        UpdateSupplier|UpdateSupplierLicence|ChangeSupplierStatus|BlacklistSupplier|null $command = null,
    ): bool {
        return $this->sameTenant($user, (string) $supplier->tenant_id)
            && $this->permits($user, 'procurement.suppliers.manage');
    }

    public function view(User $user, GetSupplier|GetSuppliers|null $query = null): bool
    {
        $facilityId = $this->context->facilityId();

        return $this->coarse($user, 'procurement.access')
            || ($this->context->hasTenant()
                && (string) $user->tenant_id === $this->context->tenantId()
                && $this->departmentScope->canAccessWorkspace($user, $facilityId));
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }
}
