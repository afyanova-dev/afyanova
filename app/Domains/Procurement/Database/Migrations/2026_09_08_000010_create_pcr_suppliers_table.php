<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.1a) — the Supplier Master
 * (19-procurement-domain.md §9–§16, §105, §229; ADR-171 D-C).
 *
 * An HTTP-command aggregate with a governed §12 lifecycle + §122 events — NOT
 * console-authored seed data (unlike the Inventory item / Lab test / Radiology
 * modality catalogs). Tenant-scoped, NOT facility-scoped (§105's facility-scoped
 * supplier is Proc.2+). `status` is open-coded `STATUS_*` string constants on
 * the model — NOT a PHP enum, NOT `#[TypeScript]` (the Inv.1 re-audit lesson).
 *
 * **NO bank-account / payment-execution data** (§229 / Inv 26 / the ADR-136
 * discipline — that is the external ERP's; `payment_terms` as a term string
 * e.g. "NET_30" is fine, an account number is not).
 *
 * `code` is tenant-unique among non-BLACKLISTED rows (a blacklisted code is
 * frozen, not reusable — §15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_suppliers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code');
            $table->string('name');
            $table->string('registration_number')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_identifier')->nullable();
            $table->string('payment_terms')->nullable();       // a term string, NOT bank data
            $table->jsonb('categories')->nullable();            // §168 — open-coded list

            $table->string('status')->default('ACTIVE');        // §12 — open-coded normalised uppercase
            $table->text('status_reason')->nullable();          // ROW-ONLY — reason for the last status change

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
        });

        DB::statement(
            'create unique index pcr_suppliers_tenant_code_unique '.
            "on pcr_suppliers (tenant_id, code) where status <> 'BLACKLISTED'"
        );

        Rls::enable('pcr_suppliers');
    }

    public function down(): void
    {
        Rls::disable('pcr_suppliers');
        Schema::dropIfExists('pcr_suppliers');
    }
};
