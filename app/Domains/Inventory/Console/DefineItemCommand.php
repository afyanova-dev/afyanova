<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Console;

use App\Domains\Inventory\Domain\Exception\InventoryCatalogConflict;
use App\Domains\Inventory\Domain\Exception\InventoryItemReclassificationNotAllowed;
use App\Domains\Inventory\Domain\ItemRepository;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\StockLedgerReader;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * PHASE 9 (increment Inv.1) ops entry point for Item Master catalog authoring
 * (18-inventory-and-warehousing-domain.md §8, §9, §180, §222; ADR-170 D-C —
 * the CP.1 `clinical:define-procedure` / Lab.1 `laboratory:define-test` /
 * Rad.1 `radiology:define-modality` precedent).
 *
 * CONSOLE-ONLY in Inv.1 — there is NO HTTP command and NO permission key.
 * Full approval / dedup / effective-dating governance is deferred ("Inv.0").
 * Every change is audited (`inventory.item_defined` / `.item_updated`).
 */
final class DefineItemCommand extends Command
{
    protected $signature = 'inventory:define-item
        {action : define|update}
        {--tenant= : tenant uuid}
        {--id= : item uuid (required for update)}
        {--code= : item code (required for define)}
        {--name= : item name (required for define)}
        {--description= : description}
        {--category= : category}
        {--type= : item type, e.g. MEDICATION (required for define)}
        {--uom= : base stock unit, e.g. tablet (required for define)}
        {--track-batch= : 1|0}
        {--track-serial= : 1|0}
        {--track-expiry= : 1|0}
        {--controlled= : 1|0 — controlled medicine (a stock DECREASE then needs a witness)}
        {--pack-unit= : buying unit, e.g. BOX (give together with --pack-size)}
        {--pack-size= : base units in one buying unit, e.g. 100}
        {--clear-pack : update only — remove the buying unit}
        {--active= : 1|0 — update only}';

    protected $description = 'Define or update an Item Master catalog entry (ADR-170 D-C).';

    public function handle(ItemRepository $items, AuditLogger $audit, StockLedgerReader $ledger): int
    {
        $tenantId = (string) $this->option('tenant');
        $action = (string) $this->argument('action');

        if ($tenantId === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }

        if (! in_array($action, ['define', 'update'], true)) {
            $this->error('action must be define|update');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $action, $items, $audit, $ledger): int {
            return $action === 'define'
                ? $this->define($tenantId, $items, $audit)
                : $this->update($items, $audit, $ledger);
        });
    }

    private function define(string $tenantId, ItemRepository $items, AuditLogger $audit): int
    {
        $code = (string) $this->option('code');
        $name = (string) $this->option('name');
        $type = (string) $this->option('type');
        $uom = (string) $this->option('uom');

        if ($code === '' || $name === '' || $type === '' || $uom === '') {
            $this->error('--code, --name, --type and --uom are required for define.');

            return self::FAILURE;
        }

        if ($items->findActiveByCode($tenantId, strtoupper(trim($code))) !== null) {
            throw InventoryCatalogConflict::itemCode($tenantId, strtoupper(trim($code)));
        }

        $id = (string) Str::uuid7();

        $item = Item::define(
            id: $id,
            tenantId: $tenantId,
            code: $code,
            name: $name,
            itemType: $type,
            uom: $uom,
            description: $this->nullableOption('description'),
            category: $this->nullableOption('category'),
            trackBatch: $this->boolOption('track-batch'),
            trackSerial: $this->boolOption('track-serial'),
            trackExpiry: $this->boolOption('track-expiry'),
            isControlled: $this->boolOption('controlled'),
            packUnit: $this->nullableOption('pack-unit'),
            packSize: $this->nullableOption('pack-size'),
        );

        $items->save($item);

        $audit->record(
            action: 'inventory.item_defined',
            targetType: 'inventory_item',
            targetId: $id,
            metadata: ['code' => $item->code, 'item_type' => $item->item_type],
        );

        $this->info("Defined inventory item [{$item->code}] ({$id}) v{$item->version}");

        return self::SUCCESS;
    }

    private function update(ItemRepository $items, AuditLogger $audit, StockLedgerReader $ledger): int
    {
        $id = (string) $this->option('id');

        if ($id === '') {
            $this->error('--id is required for update.');

            return self::FAILURE;
        }

        $item = $items->getByIdForUpdate($id);

        // One-way doors (§5, inventory-item-master-spec.md): item_type and uom
        // cannot change once the item has stock history.
        if ($this->reclassifies($item) && $ledger->hasMovementsForItem($id)) {
            throw InventoryItemReclassificationNotAllowed::forItem($id);
        }

        $item->revise(
            name: $this->nullableOption('name'),
            description: $this->nullableOption('description'),
            category: $this->nullableOption('category'),
            itemType: $this->nullableOption('type'),
            uom: $this->nullableOption('uom'),
            trackBatch: $this->nullableBoolOption('track-batch'),
            trackSerial: $this->nullableBoolOption('track-serial'),
            trackExpiry: $this->nullableBoolOption('track-expiry'),
            isControlled: $this->nullableBoolOption('controlled'),
            active: $this->nullableBoolOption('active'),
            // A blank string clears the pack; null leaves it unchanged.
            packUnit: $this->option('clear-pack') === true ? '' : $this->nullableOption('pack-unit'),
            packSize: $this->option('clear-pack') === true ? '' : $this->nullableOption('pack-size'),
        );

        $items->save($item);

        $audit->record(
            action: 'inventory.item_updated',
            targetType: 'inventory_item',
            targetId: $id,
            metadata: ['version' => $item->version],
        );

        $this->info("Updated inventory item [{$id}] -> v{$item->version}");

        return self::SUCCESS;
    }

    /**
     * Whether this `update` invocation asks to change `item_type` or `uom`
     * to a *different* value (§5).
     */
    private function reclassifies(Item $item): bool
    {
        $type = $this->nullableOption('type');
        $uom = $this->nullableOption('uom');

        return ($type !== null && strtoupper(trim($type)) !== (string) $item->item_type)
            || ($uom !== null && strtoupper(trim($uom)) !== (string) $item->uom);
    }

    private function nullableOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function boolOption(string $name): bool
    {
        return $this->nullableOption($name) === '1';
    }

    private function nullableBoolOption(string $name): ?bool
    {
        $value = $this->nullableOption($name);

        return $value === null ? null : $value === '1';
    }
}
