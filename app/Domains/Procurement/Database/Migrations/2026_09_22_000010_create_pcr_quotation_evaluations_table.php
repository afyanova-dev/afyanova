<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Proc.2b) — a structured quotation evaluation
 * (19-procurement-domain.md §36, §205–§208; ADR-174 D-B). AggregateRoot, the
 * ADR-132 context+time shape.
 *
 * One row per (quotation, evaluator) — `unique(tenant_id, quotation_id,
 * evaluator_user_id)`. Lifecycle DRAFT → SUBMITTED (`EvaluationLifecycle`).
 * The four §36 dimension scores (Commercial / Technical / Quality / Delivery,
 * each 0..100) + a weighted `overall_score` SNAPSHOT taken at SubmitEvaluation
 * by `QuotationEvaluationScorer` (default weights 40 / 30 / 15 / 15 — shipped
 * constants; per-tenant criteria config is Proc.2c, §160 / §205).
 *
 * `comments` is free text, ROW-ONLY — NEVER an event / audit metadata
 * (ADR-136 deny-list; ADR-173 D-M extended it to evaluation data). Competing
 * scores are confidential (§207 / §208 — read only via
 * `procurement.quotations.view` + the independence filter in
 * `GetQuotationEvaluationsHandler`).
 *
 * `Rls::enable` ONLY — this is a MUTABLE working record (a DRAFT is revised in
 * place), NOT an append-only ledger; `version` is REAL optimistic concurrency
 * here (contrast the append-only `pcr_rfq_awards` / `pcr_quotation_revisions`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_quotation_evaluations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->foreignUuid('rfq_id')->constrained('pcr_rfqs');
            $table->foreignUuid('quotation_id')->constrained('pcr_quotations');
            $table->uuid('evaluator_user_id');

            $table->unsignedTinyInteger('commercial_score')->nullable();    // 0..100
            $table->unsignedTinyInteger('technical_score')->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->unsignedTinyInteger('delivery_score')->nullable();
            $table->unsignedTinyInteger('overall_score')->nullable();       // weighted mean — snapshot at submit
            $table->text('comments')->nullable();                           // ROW-ONLY

            $table->string('status')->default('DRAFT');                     // open-coded — DRAFT | SUBMITTED
            $table->timestampTz('submitted_at')->nullable();

            $table->uuid('created_by_user_id');                             // = evaluator_user_id
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'quotation_id', 'evaluator_user_id']);
            $table->index(['tenant_id', 'rfq_id', 'status']);
        });

        Rls::enable('pcr_quotation_evaluations');
    }

    public function down(): void
    {
        Rls::disable('pcr_quotation_evaluations');
        Schema::dropIfExists('pcr_quotation_evaluations');
    }
};
