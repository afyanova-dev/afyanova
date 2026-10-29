<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.2c) — per-tenant quotation-evaluation configuration
 * (19-procurement-domain.md §23, §36, §205; ADR-175 D-B).
 *
 * ONE row per tenant (`unique(tenant_id)`). Tenant-scoped, integer-versioned
 * reference data — NOT the ADR-132 record shape (no facility / patient / author
 * / clinical time): this is config, not a record (the `ProcedureDefinition` /
 * ADR-146 D-D precedent). `afyanova:conformance`'s column-shape check requires
 * only `id` uuid + `tenant_id` uuid + `version` on a tenant-scoped table.
 *
 * Authored ONLY via the `procurement:configure-evaluation` console command
 * (`clinical:define-procedure` precedent) — NO HTTP bus command, NO permission
 * key. A tenant with NO row behaves EXACTLY as Proc.2b
 * (`EvaluationSettingsReader::forTenant()` → `EvaluationCriteria::defaults()`).
 *
 * `evaluation_required_above` is a `Money` amount — the §23 "thresholds are
 * organizational policy" gate. `pass_threshold` flags a below-par `overall` in
 * `GetQuotationEvaluations`; it NEVER blocks an award (§35).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_evaluation_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->unique();

            $table->unsignedTinyInteger('commercial_weight')->default(40);
            $table->unsignedTinyInteger('technical_weight')->default(30);
            $table->unsignedTinyInteger('quality_weight')->default(15);
            $table->unsignedTinyInteger('delivery_weight')->default(15);

            $table->boolean('commercial_required')->default(true);
            $table->boolean('technical_required')->default(true);
            $table->boolean('quality_required')->default(true);
            $table->boolean('delivery_required')->default(true);

            $table->unsignedTinyInteger('pass_threshold')->nullable();      // overall < this → flagged, never blocks
            $table->decimal('evaluation_required_above', 18, 2)->nullable(); // Money — §23 hard-gate threshold

            $table->unsignedInteger('version')->default(1);                 // bumped in place by the console
            $table->timestampsTz();
        });

        Rls::enable('pcr_evaluation_settings');
    }

    public function down(): void
    {
        Rls::disable('pcr_evaluation_settings');
        Schema::dropIfExists('pcr_evaluation_settings');
    }
};
