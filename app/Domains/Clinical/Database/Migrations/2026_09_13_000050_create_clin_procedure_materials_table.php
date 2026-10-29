<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 6 (increment CP.1) — the append-only materials-consumed log for a
 * procedure (13-clinical-procedures-domain.md §50–§53; ADR-137, ADR-146 D-N).
 *
 * A REQUIREMENT vs ACTUAL distinction (13 §51): `clin_procedure_definitions
 * .material_requirements` names what is typically needed; this table records
 * what was ACTUALLY consumed for one instance — recordable during OR after
 * execution (13 §52). Clinical Procedures records the fact of consumption;
 * Inventory (PHASE 9) owns the stock movement (13 §50, ADR-137) — NO stock
 * balances here.
 *
 * `procedure_id` carries a real foreign key to `clin_procedures` — an
 * INTRA-domain reference (IC §10). `material_ref` / `material_code_system` are
 * OPEN strings (no terminology engine). Append-only: a mis-keyed line is
 * corrected via a compensating `AmendProcedure` note, not an `UPDATE` (a void /
 * adjust flow is deferred). The `version` column exists only to satisfy the
 * aggregate table shape and stays 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_procedure_materials', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('procedure_id')->constrained('clin_procedures'); // intra-domain FK (IC §10)

            $table->string('material_ref');                    // OPEN
            $table->string('material_code_system')->nullable(); // OPEN
            $table->decimal('quantity', 12, 3);
            $table->string('unit')->nullable();                 // OPEN
            $table->string('batch_ref')->nullable();            // 13 §53

            $table->uuid('recorded_by_user_id');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);  // stays 0 — append-only
            $table->timestampsTz();

            $table->index(['tenant_id', 'procedure_id']);
        });

        Rls::enable('clin_procedure_materials');
        Rls::enableAppendOnly('clin_procedure_materials');
    }

    public function down(): void
    {
        Rls::disable('clin_procedure_materials');
        Schema::dropIfExists('clin_procedure_materials');
    }
};
