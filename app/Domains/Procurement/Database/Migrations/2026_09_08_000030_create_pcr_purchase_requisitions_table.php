<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.1a) — the Purchase Requisition
 * (19-procurement-domain.md §17–§24, §244; ADR-171 D-E). A request to acquire
 * goods/services — distinct from an Inventory Requisition (§19). AggregateRoot,
 * the ADR-132 context+time shape.
 *
 * §21 lifecycle FRONT only: DRAFT → SUBMITTED → APPROVED (+ REJECTED from
 * SUBMITTED, + CANCELLED from DRAFT|SUBMITTED). The SOURCING → ORDERED →
 * FULFILLED tail is NOT wired — an APPROVED requisition stays APPROVED (a PO may
 * cite it via the optional `requisition_id`, but the rollup is deferred —
 * ADR-171 F11).
 *
 * `reason` / `rejection_reason` / `emergency_reason` are free text, ROW-ONLY —
 * NEVER event / audit metadata (the ADR-136 deny-list). `emergency_reason` is
 * non-blank REQUIRED when `emergency` (enforced in `CreatePurchaseRequisition`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_purchase_requisitions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('requisition_number');                    // PR-%08d
            $table->uuid('requesting_department_ref')->nullable();   // opaque, no FK (§224)
            $table->string('priority')->default('ROUTINE');          // §79 — open-coded
            $table->text('reason')->nullable();                      // ROW-ONLY
            $table->decimal('estimated_value', 18, 2)->nullable();   // Money

            $table->boolean('emergency')->default(false);
            $table->text('emergency_reason')->nullable();            // ROW-ONLY, required when emergency

            $table->string('status')->default('DRAFT');              // §21 — open-coded
            $table->uuid('reviewed_by_user_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();            // ROW-ONLY

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'requisition_number']);
            $table->index(['tenant_id', 'facility_id', 'status']);
        });

        Rls::enable('pcr_purchase_requisitions');
    }

    public function down(): void
    {
        Rls::disable('pcr_purchase_requisitions');
        Schema::dropIfExists('pcr_purchase_requisitions');
    }
};
