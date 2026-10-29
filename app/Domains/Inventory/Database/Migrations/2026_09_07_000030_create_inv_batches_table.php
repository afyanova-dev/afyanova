<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Inv.1) — the Batch / Lot record
 * (18-inventory-and-warehousing-domain.md §39, §42, §43, §247; ADR-170 D-E).
 *
 * `inv_batches` is an AggregateRoot (§247 "Batch Aggregate") — a manufactured
 * lot with its own identity and a later lifecycle (quarantine / recall status
 * arrive with Inv.6). Aggregate-0 version shape (a record, not reference data).
 * NO events in Inv.1 (§236 / §323 name no batch event).
 *
 * `batch_number` is meaningful only per item (§39) — `unique(tenant_id,
 * item_id, batch_number)`. Tenant-scoped, NOT facility-scoped (a lot exists at
 * locations via balances). `supplier_ref` is an OPAQUE nullable string, NO
 * `pcr_` FK (the T.6 opaque-ref / §55 precedent). `expiry_date` is required
 * when the item's `track_expiry` (enforced by `ReceiveStock` — §43 / Inv 28:
 * expiry belongs to the batch, not the generic item).
 *
 * Created find-or-create by (item_id, batch_number) inside `ReceiveStock`;
 * `track_batch = false` items never get a batch row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->foreignUuid('item_id')->constrained('inv_items'); // intra-inv_ FK

            $table->string('batch_number');
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();   // required when item.track_expiry (§43 / Inv 28)
            $table->string('supplier_ref')->nullable(); // OPAQUE — no pcr_ FK (T.6 precedent)
            $table->timestampTz('received_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'item_id', 'batch_number']);
            $table->index(['tenant_id', 'item_id']);
            $table->index(['tenant_id', 'expiry_date']);
        });

        Rls::enable('inv_batches');
    }

    public function down(): void
    {
        Rls::disable('inv_batches');
        Schema::dropIfExists('inv_batches');
    }
};
