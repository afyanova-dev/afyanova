<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\GoodsReceiptRepository;
use App\Domains\Inventory\Domain\Model\GoodsReceipt;
use App\Domains\Inventory\Domain\Model\GoodsReceiptLine;
use App\Domains\Inventory\Domain\Model\StockMovement;
use App\Domains\Inventory\Domain\Model\SupplierReturn;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\Service\StockLedgerService;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Infrastructure\Sequence\MovementNumberSequence;
use App\Domains\Inventory\Infrastructure\Sequence\SupplierReturnNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ReturnGoodsToSupplierHandler
{
    public function __construct(
        private readonly StockBalanceRepository $balances,
        private readonly StockContextResolver $resolver,
        private readonly StockLedgerService $ledger,
        private readonly MovementNumberSequence $movementNumbers,
        private readonly SupplierReturnNumberSequence $returnNumbers,
        private readonly TenantContext $context,
        private readonly GoodsReceiptRepository $receipts,
    ) {}

    public function __invoke(ReturnGoodsToSupplier $command): string
    {
        $receipt = $this->receipts->getByIdForUpdate($command->receiptId);
        if ((string) $receipt->status !== GoodsReceipt::STATUS_POSTED) {
            throw new InvalidArgumentException('Only a posted goods receipt can be returned.');
        }

        $this->resolver->assertActorScopedToFacility(
            (string) $receipt->facility_id,
            'inventory.receipts.create',
            $command->receiptId,
        );
        $location = $this->resolver->resolveActiveLocation($command->locationId);
        if ($location->facilityId !== (string) $receipt->facility_id) {
            throw new InvalidArgumentException('A supplier return must use a location in the receipt facility.');
        }

        $receiptLines = GoodsReceiptLine::query()
            ->where('receipt_id', $receipt->id)
            ->whereIn('id', array_column($command->lines, 'receipt_line_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (GoodsReceiptLine $line): string => (string) $line->id);
        if ($receiptLines->count() !== count($command->lines)) {
            throw new InvalidArgumentException('Every returned line must belong to the selected goods receipt.');
        }

        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();
        $return = SupplierReturn::record(
            id: $command->returnId,
            tenantId: $tenantId,
            facilityId: (string) $receipt->facility_id,
            returnNumber: $this->returnNumbers->next($tenantId),
            receiptId: (string) $receipt->id,
            supplierRef: $receipt->supplier_ref !== null ? (string) $receipt->supplier_ref : null,
            locationId: $location->locationId,
            reason: $command->reason,
            occurredAt: $now,
            recordedAt: $now,
        );
        $return->save();

        foreach ($command->lines as $lineNumber => $input) {
            /** @var GoodsReceiptLine|null $receiptLine */
            $receiptLine = $receiptLines->get($input['receipt_line_id']);
            if ($receiptLine === null || $receiptLine->movement_id === null) {
                throw new InvalidArgumentException('Only goods-receipt lines that posted stock can be returned.');
            }

            $alreadyReturned = DB::table('inv_supplier_return_lines')
                ->where('receipt_line_id', $receiptLine->id)
                ->sum('quantity');
            $returnable = Quantity::sub((string) $receiptLine->quantity_accepted, (string) $alreadyReturned);
            if (Quantity::compare($input['quantity'], $returnable) > 0) {
                throw new InvalidArgumentException(
                    "Return quantity exceeds the remaining accepted quantity ({$returnable}) for receipt line [{$receiptLine->id}]."
                );
            }

            $batchId = $receiptLine->batch_id !== null ? (string) $receiptLine->batch_id : null;
            $movement = StockMovement::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => (string) $receipt->facility_id,
                'movement_number' => $this->movementNumbers->next($tenantId),
                'movement_type' => StockMovement::TYPE_RETURN_OUT,
                'direction' => StockMovement::DIRECTION_OUT,
                'quantity' => Quantity::of($input['quantity']),
                'uom' => (string) $receiptLine->uom,
                'item_id' => (string) $receiptLine->item_id,
                'location_id' => $location->locationId,
                'batch_id' => $batchId,
                'source_type' => 'SUPPLIER_RETURN',
                'source_ref' => $command->returnId,
                'po_ref' => $receipt->po_ref !== null ? (string) $receipt->po_ref : null,
                'po_line_ref' => $receiptLine->po_line_ref !== null ? (string) $receiptLine->po_line_ref : null,
                'goods_receipt_ref' => (string) $receipt->id,
                'reason_text' => strtoupper(trim($command->reason)),
                'actor_user_id' => $command->actorId,
                'occurred_at' => $now,
                'recorded_at' => $now,
                'version' => 0,
            ]);

            $balance = $this->balances->forUpdateOrCreate(
                $tenantId,
                (string) $receipt->facility_id,
                (string) $receiptLine->item_id,
                $location->locationId,
                $batchId,
            );
            $this->ledger->postSupplierReturn($balance, $movement, $command->returnId);
            $this->balances->save($balance);

            DB::table('inv_supplier_return_lines')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => (string) $receipt->facility_id,
                'return_id' => $command->returnId,
                'receipt_line_id' => (string) $receiptLine->id,
                'line_no' => $lineNumber + 1,
                'item_id' => (string) $receiptLine->item_id,
                'batch_id' => $batchId,
                'quantity' => Quantity::of($input['quantity']),
                'uom' => (string) $receiptLine->uom,
                'movement_id' => (string) $movement->id,
                'credit_note_ref' => isset($input['credit_note_ref']) && trim((string) $input['credit_note_ref']) !== ''
                    ? trim((string) $input['credit_note_ref'])
                    : null,
                'notes' => isset($input['notes']) && trim((string) $input['notes']) !== ''
                    ? trim((string) $input['notes'])
                    : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return (string) $return->return_number;
    }
}
