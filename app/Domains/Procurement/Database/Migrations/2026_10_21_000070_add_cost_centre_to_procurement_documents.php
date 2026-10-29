<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 / P2-01 — cost centre + budget reference on the buying documents.
 *
 * Both are optional, free-form references supplied by the hospital (for
 * example `CC-PHARMACY` / `BUDGET-2026-Q4`). They do not participate in money
 * or approval calculations — they make the commitment traceable and appear on
 * the PO/request print and Excel exports.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_purchase_orders', function (Blueprint $table): void {
            $table->string('cost_centre_ref', 100)->nullable();
            $table->string('budget_ref', 100)->nullable();
        });

        Schema::table('pcr_purchase_requisitions', function (Blueprint $table): void {
            $table->string('cost_centre_ref', 100)->nullable();
            $table->string('budget_ref', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pcr_purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['cost_centre_ref', 'budget_ref']);
        });

        Schema::table('pcr_purchase_requisitions', function (Blueprint $table): void {
            $table->dropColumn(['cost_centre_ref', 'budget_ref']);
        });
    }
};
