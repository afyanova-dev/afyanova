<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.3a) — a purchase-contract line
 * (19-procurement-domain.md §40, §41; ADR-176 D-B). Plain child of `Contract`
 * — NOT an AggregateRoot (the `pcr_rfq_lines` precedent).
 *
 * STOCK lines are validated + snapshotted via `InventoryApi::getItem`
 * (`ProcurementContextResolver::resolveLine` — NO cross-prefix FK on `item_ref`).
 * `agreed_unit_price` is the contract price the PO line is seeded from (ADR-176
 * D4). `quantity_ceiling` is the per-line cap (NULL = no cap); the CUMULATIVE
 * Σ-across-POs guarantee (Inv 33) is Proc.3b.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_contract_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->foreignUuid('contract_id')->constrained('pcr_contracts');
            $table->unsignedInteger('line_no');
            $table->string('line_type');                                    // STOCK | SERVICE

            $table->uuid('item_ref')->nullable();                           // no cross-prefix FK
            $table->string('item_code')->nullable();
            $table->string('item_name')->nullable();
            $table->text('description')->nullable();

            $table->decimal('agreed_unit_price', 18, 2);                    // Money
            $table->decimal('quantity_ceiling', 18, 4)->nullable();         // Quantity; NULL = no cap
            $table->string('uom');

            $table->unsignedBigInteger('version')->default(0);              // shape-only
            $table->timestampsTz();

            $table->unique(['tenant_id', 'contract_id', 'line_no']);
        });

        Rls::enable('pcr_contract_lines');
    }

    public function down(): void
    {
        Rls::disable('pcr_contract_lines');
        Schema::dropIfExists('pcr_contract_lines');
    }
};
