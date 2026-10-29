<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Application\ProcurementContextResolver;
use App\Domains\Procurement\Domain\ContractRepository;
use App\Domains\Procurement\Domain\Exception\SupplierNotOrderable;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Domains\Procurement\Domain\Model\ContractLine;
use App\Domains\Procurement\Domain\Money;
use App\Domains\Procurement\Domain\Quantity;
use App\Domains\Procurement\Domain\SupplierRepository;
use App\Domains\Procurement\Infrastructure\Sequence\ContractNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Orchestrates `CreateContract` (ADR-176 D-C).
 *
 * The supplier must be ACTIVE → resolve + snapshot every line via
 * `ProcurementContextResolver::resolveLine` (STOCK → `InventoryApi::getItem`,
 * reused — NO new port) → allocate `CON-%08d` → create DRAFT → save → write
 * `pcr_contract_lines` (one txn). Records NO event. NO facility scope
 * (tenant-wide — the `RegisterSupplier` precedent; `ContractPolicy::create` is
 * the gate).
 *
 * `ContractRepository` is the first repository parameter → the bus resolves
 * `ContractPolicy`.
 */
final class CreateContractHandler
{
    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly SupplierRepository $suppliers,
        private readonly ProcurementContextResolver $resolver,
        private readonly ContractNumberSequence $contractNumbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreateContract $command): void
    {
        $supplier = $this->suppliers->getById($command->supplierId);
        if (! $supplier->isOrderable()) {
            throw SupplierNotOrderable::status($command->supplierId, (string) $supplier->status);
        }

        $resolved = [];
        foreach ($command->lines as $index => $line) {
            $item = $this->resolver->resolveLine($line, $index + 1);
            $resolved[] = [
                'lineType' => $item['lineType'],
                'itemRef' => $item['itemRef'],
                'itemCode' => $item['itemCode'],
                'itemName' => $item['itemName'],
                'description' => $item['description'],
                'uom' => $item['uom'],
                'agreedUnitPrice' => Money::of((string) $line['agreedUnitPrice']),
                'quantityCeiling' => isset($line['quantityCeiling']) && trim((string) $line['quantityCeiling']) !== ''
                    ? Quantity::of((string) $line['quantityCeiling'])
                    : null,
            ];
        }

        $tenantId = $this->context->tenantId();

        $contract = Contract::create(
            id: $command->contractId,
            tenantId: $tenantId,
            contractNumber: $this->contractNumbers->next($tenantId),
            supplierId: $command->supplierId,
            title: $command->title,
            createdByUserId: $command->actorId,
            effectiveDate: $command->effectiveDate,
            expiryDate: $command->expiryDate,
            reason: $command->reason,
            terms: $command->terms,
            currency: $command->currency,
        );

        $this->contracts->save($contract);

        foreach ($resolved as $index => $line) {
            ContractLine::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'contract_id' => $command->contractId,
                'line_no' => $index + 1,
                'line_type' => $line['lineType'],
                'item_ref' => $line['itemRef'],
                'item_code' => $line['itemCode'],
                'item_name' => $line['itemName'],
                'description' => $line['description'],
                'agreed_unit_price' => $line['agreedUnitPrice'],
                'quantity_ceiling' => $line['quantityCeiling'],
                'uom' => $line['uom'],
            ]);
        }
    }
}
