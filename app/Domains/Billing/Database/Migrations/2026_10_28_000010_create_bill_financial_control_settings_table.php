<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.7a) — per-tenant financial-control thresholds
 * (20-billing-and-payments-domain.md §74 / §82 / §157 / §165 / §167; ADR-191
 * G-3a).
 *
 * ONE row per tenant (`unique(tenant_id)`). CONSOLE-AUTHORED ONLY — via
 * `billing:configure-financial-control`; there is NO HTTP bus command and NO
 * permission key (the `pcr_evaluation_settings` / `procurement:configure-
 * evaluation` precedent). A write-off / adjustment whose amount is `<= ` the
 * relevant threshold MAY be request-and-applied by one actor; above it, a
 * DISTINCT approver is mandatory. A NULL threshold = ALWAYS dual-control; a
 * tenant with NO row = always dual-control.
 *
 * `afyanova:conformance`'s column-shape check requires only `id` uuid +
 * `tenant_id` uuid + `version` on a tenant-scoped table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_financial_control_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->unique();

            $table->decimal('writeoff_dual_control_above', 18, 2)->nullable();
            $table->decimal('adjustment_dual_control_above', 18, 2)->nullable();

            $table->unsignedInteger('version')->default(1);   // bumped in place by the console
            $table->timestampsTz();
        });

        Rls::enable('bill_financial_control_settings');
    }

    public function down(): void
    {
        Rls::disable('bill_financial_control_settings');
        Schema::dropIfExists('bill_financial_control_settings');
    }
};
