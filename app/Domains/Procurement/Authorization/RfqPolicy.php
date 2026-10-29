<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Authorization;

use App\Domains\Procurement\Application\Command\AwardRfq;
use App\Domains\Procurement\Application\Command\CancelRfq;
use App\Domains\Procurement\Application\Command\CloseRfq;
use App\Domains\Procurement\Application\Command\CreateRfq;
use App\Domains\Procurement\Application\Command\IssueRfq;
use App\Domains\Procurement\Application\Command\RecordQuotation;
use App\Domains\Procurement\Application\Command\RequireRfqEvaluation;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

/**
 * Rfq aggregate policy (ADR-173 D-H).
 *
 * `create` is the coarse class-level check for `CreateRfq` (the handler's
 * ADR-133 refinement enforces the real per-facility scope — the
 * `ImagingOrderPolicy::create` precedent). Per-subject, all at the RFQ's own
 * facility: `manage` (issue / close / cancel — `procurement.rfqs.manage`);
 * `award` (`procurement.awards.decide` — §37 different actor from the creator,
 * enforced in the handler); `record` (`RecordQuotation` routes through this
 * policy because its handler injects `RfqRepository` first —
 * `procurement.quotations.record`). `view` gates `GetRfq` / `SearchRfqs`
 * (metadata + award outcome, `procurement.access`).
 */
final class RfqPolicy extends BasePolicy
{
    public function create(User $user, ?CreateRfq $command = null): bool
    {
        return $this->coarse($user, 'procurement.rfqs.manage');
    }

    public function manage(
        User $user,
        Rfq $rfq,
        IssueRfq|CloseRfq|CancelRfq|RequireRfqEvaluation|null $command = null,
    ): bool {
        return $this->hasScope($user, $rfq, 'procurement.rfqs.manage');
    }

    public function award(User $user, Rfq $rfq, ?AwardRfq $command = null): bool
    {
        return $this->hasScope($user, $rfq, 'procurement.awards.decide');
    }

    public function record(User $user, Rfq $rfq, ?RecordQuotation $command = null): bool
    {
        return $this->hasScope($user, $rfq, 'procurement.quotations.record');
    }

    public function view(User $user): bool
    {
        return $this->coarse($user, 'procurement.access');
    }

    private function coarse(User $user, string $key): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, $key);
    }

    private function hasScope(User $user, Rfq $rfq, string $key): bool
    {
        return $this->sameTenant($user, (string) $rfq->tenant_id)
            && in_array($key, $this->scope->effectivePermissions($user, (string) $rfq->facility_id), true);
    }
}
