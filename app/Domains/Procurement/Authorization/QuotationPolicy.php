<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Authorization;

use App\Domains\Procurement\Application\Command\ReviseQuotation;
use App\Domains\Procurement\Application\Command\WithdrawQuotation;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Quotation aggregate policy (ADR-173 D-H).
 *
 * `record` is the per-subject check for revise / withdraw
 * (`procurement.quotations.record` at the quotation's facility). `view` gates
 * `GetRfqQuotations` / `CompareQuotations` on **`procurement.quotations.view`**
 * (§101 / §208 — the confidentiality tier, strictly stronger than
 * `procurement.access`; every quotation read exposes competing prices).
 */
final class QuotationPolicy extends BasePolicy
{
    public function record(
        User $user,
        Quotation $quotation,
        ReviseQuotation|WithdrawQuotation|null $command = null,
    ): bool {
        return $this->sameTenant($user, (string) $quotation->tenant_id)
            && in_array('procurement.quotations.record', $this->scope->effectivePermissions($user, (string) $quotation->facility_id), true);
    }

    public function view(User $user): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'procurement.quotations.view');
    }
}
