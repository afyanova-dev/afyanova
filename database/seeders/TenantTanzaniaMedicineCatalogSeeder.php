<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Inventory\Domain\Model\Item;
use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the comprehensive Tanzania Medicines Master Catalogue (NEMLIT / TMDA / NHIF)
 * for item type MEDICATION into `inv_items` for one or all tenants.
 */
class TenantTanzaniaMedicineCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(?string $targetTenantId = null): void
    {
        if ($targetTenantId !== null) {
            $tenant = Tenant::query()->findOrFail($targetTenantId);
            $this->seedTenantCatalogue($tenant->id);

            return;
        }

        $tenants = Tenant::all();
        if ($tenants->isEmpty()) {
            $demoTenant = Tenant::query()->firstOrCreate(
                ['slug' => 'afyanova-demo'],
                ['name' => 'AfyaNova Demo Health', 'status' => 'ACTIVE'],
            );
            $tenants = collect([$demoTenant]);
        }

        foreach ($tenants as $tenant) {
            $this->seedTenantCatalogue($tenant->id);
        }
    }

    /**
     * Direct invocation helper for a specific tenant ID.
     */
    public static function seed(string $tenantId): void
    {
        (new self)->run($tenantId);
    }

    private function seedTenantCatalogue(string $tenantId): void
    {
        $dataFile = database_path('data/tanzania_medicines.php');
        if (! file_exists($dataFile)) {
            throw new \RuntimeException("Catalogue data file not found: {$dataFile}");
        }

        /** @var list<array<string, mixed>> $catalog */
        $catalog = require $dataFile;

        TenantContextScope::run($tenantId, null, function () use ($tenantId, $catalog): void {
            // Fetch existing active codes for idempotency
            $existingCodes = Item::query()
                ->where('tenant_id', $tenantId)
                ->where('active', true)
                ->pluck('code')
                ->flip()
                ->all();

            $now = now();
            $itemsToInsert = [];
            $itemsToUpdate = [];

            foreach ($catalog as $entry) {
                $code = strtoupper(trim((string) $entry['code']));
                if (isset($existingCodes[$code])) {
                    $itemsToUpdate[] = [
                        'code' => $code,
                        'name' => (string) $entry['name'],
                        'category' => isset($entry['category']) ? (string) $entry['category'] : null,
                        'description' => isset($entry['description']) ? (string) $entry['description'] : null,
                        'track_batch' => (bool) ($entry['track_batch'] ?? false),
                        'track_expiry' => (bool) ($entry['track_expiry'] ?? false),
                        'is_controlled' => (bool) ($entry['is_controlled'] ?? false),
                        'pack_unit' => isset($entry['pack_unit']) ? (string) $entry['pack_unit'] : null,
                        'pack_size' => isset($entry['pack_size']) ? (string) $entry['pack_size'] : null,
                    ];

                    continue;
                }

                // Domain validation via Item::define ensuring all constraints pass
                $item = Item::define(
                    id: (string) Str::uuid7(),
                    tenantId: $tenantId,
                    code: $code,
                    name: (string) $entry['name'],
                    itemType: (string) $entry['item_type'],
                    uom: (string) $entry['uom'],
                    description: isset($entry['description']) ? (string) $entry['description'] : null,
                    category: isset($entry['category']) ? (string) $entry['category'] : null,
                    trackBatch: (bool) ($entry['track_batch'] ?? false),
                    trackSerial: (bool) ($entry['track_serial'] ?? false),
                    trackExpiry: (bool) ($entry['track_expiry'] ?? false),
                    isControlled: (bool) ($entry['is_controlled'] ?? false),
                    packUnit: isset($entry['pack_unit']) ? (string) $entry['pack_unit'] : null,
                    packSize: isset($entry['pack_size']) ? (string) $entry['pack_size'] : null,
                );

                $itemsToInsert[] = [
                    'id' => $item->id,
                    'tenant_id' => $item->tenant_id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'description' => $item->description,
                    'category' => $item->category,
                    'item_type' => $item->item_type,
                    'uom' => $item->uom,
                    'pack_unit' => $item->pack_unit,
                    'pack_size' => $item->pack_size,
                    'track_batch' => $item->track_batch,
                    'track_serial' => $item->track_serial,
                    'track_expiry' => $item->track_expiry,
                    'is_controlled' => $item->is_controlled,
                    'active' => true,
                    'version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::transaction(function () use ($tenantId, $itemsToInsert, $itemsToUpdate, $now): void {
                if (! empty($itemsToInsert)) {
                    foreach (array_chunk($itemsToInsert, 100) as $chunk) {
                        DB::table('inv_items')->insert($chunk);
                    }
                }

                foreach ($itemsToUpdate as $up) {
                    DB::table('inv_items')
                        ->where('tenant_id', $tenantId)
                        ->where('code', $up['code'])
                        ->where('active', true)
                        ->update([
                            'name' => $up['name'],
                            'category' => $up['category'],
                            'description' => $up['description'],
                            'track_batch' => $up['track_batch'],
                            'track_expiry' => $up['track_expiry'],
                            'is_controlled' => $up['is_controlled'],
                            'pack_unit' => $up['pack_unit'],
                            'pack_size' => $up['pack_size'],
                            'updated_at' => $now,
                        ]);
                }
            });
        });
    }
}
