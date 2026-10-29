<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.3a) — the purchase-contract record
 * (19-procurement-domain.md §40, §248; ADR-176 D-B). AggregateRoot.
 *
 * Tenant-scoped, NOT facility-scoped (the `Supplier` model, ADR-176 D6) — the
 * ADR-132 time part only (`created_by` / `occurred_at` / `recorded_at` /
 * `version`), no `facility_id` / `patient_id` / `encounter_id`.
 *
 * Lifecycle DRAFT → ACTIVE → TERMINATED (`ContractLifecycle`). **NO EXPIRED
 * status** — §194 (Inv 32) is enforced check-at-use: `CreatePurchaseOrder(contractId)`
 * refuses a non-ACTIVE / past-`expiry_date` contract. The ACTIVE → EXPIRED flip
 * + a sweep are Proc.3c/4.
 *
 * `reason` / `terms` / `termination_reason` are free text — ROW-ONLY, NEVER an
 * event / audit metadata (ADR-136).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_contracts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('contract_number');                                  // CON-%08d
            $table->foreignUuid('supplier_id')->constrained('pcr_suppliers');
            $table->string('title');
            $table->text('reason')->nullable();                                 // ROW-ONLY

            $table->date('effective_date');
            $table->date('expiry_date');
            $table->text('terms')->nullable();                                  // ROW-ONLY
            $table->string('currency')->default('TZS');

            $table->string('status')->default('DRAFT');                         // DRAFT | ACTIVE | TERMINATED
            $table->text('termination_reason')->nullable();                     // ROW-ONLY
            $table->uuid('activated_by_user_id')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->uuid('terminated_by_user_id')->nullable();
            $table->timestampTz('terminated_at')->nullable();

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'contract_number']);
            $table->index(['tenant_id', 'supplier_id', 'status']);
        });

        Rls::enable('pcr_contracts');
    }

    public function down(): void
    {
        Rls::disable('pcr_contracts');
        Schema::dropIfExists('pcr_contracts');
    }
};
