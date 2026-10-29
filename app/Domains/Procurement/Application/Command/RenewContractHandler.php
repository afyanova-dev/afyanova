<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Model\ContractLine;
use App\Domains\Procurement\Domain\Service\ContractLifecycle;
use App\Domains\Procurement\Infrastructure\Sequence\ContractNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Orchestrates `RenewContract` (ADR-178 D-C / D-D / D-J).
 *
 * Load the old contract `FOR UPDATE` → it must be ACTIVE → mint a NEW
 * `pcr_contracts` row (`CON-%08d`, old supplier / currency / terms, title
 * overridable, `supersedes_contract_id` → old, lands ACTIVE directly) →
 * re-create every `pcr_contract_lines` row with FRESH ids (same price / ceiling
 * / uom — D6: new identities, zero consumption) → flip the old contract →
 * `SUPERSEDED`. One bus transaction; no event. Historical POs keep pointing at
 * the old (now frozen) rows — never rewritten (§303 Invariant 31).
 *
 * `ContractRepository` is the first repository parameter → `ContractPolicy::manage`
 * against the OLD contract (`RenewContract::subjectId()`).
 */
final class RenewContractHandler
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractLifecycle $lifecycle,
        private readonly ContractNumberSequence $contractNumbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RenewContract $command): void
    {
        $old = $this->contracts->getByIdForUpdate($command->contractId);

        $this->lifecycle->assertCanSupersede((string) $old->status);

        $tenantId = $this->context->tenantId();

        $new = Contract::create(
            id: $command->newContractId,
            tenantId: $tenantId,
            contractNumber: $this->contractNumbers->next($tenantId),
            supplierId: (string) $old->supplier_id,
            title: $command->title ?? (string) $old->title,
            createdByUserId: $command->actorId,
            effectiveDate: $command->effectiveDate,
            expiryDate: $command->expiryDate,
            reason: $command->reason,
            terms: $old->terms !== null ? (string) $old->terms : null,
            currency: (string) $old->currency,
        );
        $new->supersedes_contract_id = (string) $old->id;
        $new->markActive($command->actorId);

        $this->contracts->save($new);

        $oldLines = ContractLine::query()
            ->where('contract_id', $old->id)
            ->orderBy('line_no')
            ->get();

        foreach ($oldLines as $line) {
            ContractLine::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'contract_id' => $command->newContractId,
                'line_no' => (int) $line->line_no,
                'line_type' => (string) $line->line_type,
                'item_ref' => $line->item_ref !== null ? (string) $line->item_ref : null,
                'item_code' => $line->item_code,
                'item_name' => $line->item_name,
                'description' => $line->description,
                'agreed_unit_price' => (string) $line->agreed_unit_price,
                'quantity_ceiling' => $line->quantity_ceiling !== null ? (string) $line->quantity_ceiling : null,
                'uom' => (string) $line->uom,
            ]);
        }

        $old->markSuperseded($command->actorId);

        $this->contracts->save($old);
    }
}
