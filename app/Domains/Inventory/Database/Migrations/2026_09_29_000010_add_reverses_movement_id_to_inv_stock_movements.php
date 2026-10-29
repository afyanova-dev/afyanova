<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Pharm.3b — ADR-185) — the "corrective inverse" movement
 * link (18-inventory-and-warehousing-domain.md §84, §326; ADR-185 D-C —
 * **amends ADR-170 by pointer**).
 *
 * `ReverseConsumption` appends a compensating IN movement
 * (`movement_type = CONSUMPTION_REVERSAL`) that points back at the exact original
 * CONSUMPTION OUT via `reverses_movement_id`. The UNIQUE partial index enforces
 * "one reversal per movement" (the idempotency + no-double-reverse guard).
 * NULLABLE — every pre-Pharm.3b movement, and every RECEIPT / CONSUMPTION /
 * ADJUSTMENT, has NULL. No RLS change — `inv_stock_movements` already carries
 * tenant FORCE RLS + append-only (Inv.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            $table->uuid('reverses_movement_id')->nullable()->after('po_ref'); // the reversed CONSUMPTION OUT — plain uuid, NO FK
        });

        DB::statement(
            'create unique index inv_stock_movements_one_reversal_per_movement '.
            'on inv_stock_movements (tenant_id, reverses_movement_id) '.
            'where reverses_movement_id is not null'
        );
    }

    public function down(): void
    {
        DB::statement('drop index if exists inv_stock_movements_one_reversal_per_movement');
        Schema::table('inv_stock_movements', function (Blueprint $table): void {
            $table->dropColumn('reverses_movement_id');
        });
    }
};
