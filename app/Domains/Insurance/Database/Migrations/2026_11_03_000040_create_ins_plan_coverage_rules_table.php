<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Ins.3) — plan coverage rules
 * (21-insurance-and-payer-domain.md §31–§45, §69; ADR-200 D-A).
 *
 * One effective-dated rule per (plan, service_category) scope — the
 * `bill_tariffs` non-overlapping-by-construction idiom: `effective_to` is an
 * EXCLUSIVE upper bound, `SetPlanCoverageRule` closes the prior open row and
 * rejects an `effective_from` not strictly after it, and a partial unique on
 * the open row (`effective_to is null`) caps it at one. Rule TERMS are
 * IMMUTABLE per row (history preserved — §162 / §225 / §331). Mutable working
 * record — `Rls::enable` only. NO events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ins_plan_coverage_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('plan_id');                            // SET-ONCE — no FK
            $table->string('service_category');                 // CONSULTATION | PROCEDURE | LABORATORY | RADIOLOGY | MEDICATION | BED_DAY | OTHER

            $table->string('coverage_status');                  // COVERED | NOT_COVERED | REQUIRES_AUTHORIZATION
            $table->unsignedTinyInteger('coverage_percent')->nullable();   // 0..100 — meaningful for COVERED
            $table->decimal('co_pay_amount', 18, 4)->nullable();           // Money::of() scale-2
            $table->string('co_pay_currency')->default('TZS');
            $table->decimal('fixed_benefit_amount', 18, 4)->nullable();    // §35 — payer pays at most this
            $table->boolean('authorization_required')->default(false);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();           // EXCLUSIVE upper bound; NULL = open/current
            $table->string('status')->default('CURRENT');       // CURRENT | SUPERSEDED | ENDED

            $table->uuid('created_by_user_id')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'plan_id', 'service_category', 'effective_from']);
        });

        // ADR-200 D-A — at most one open rule per (tenant, plan, service_category).
        DB::statement(
            'create unique index ins_plan_coverage_rules_one_open_per_scope
                 on ins_plan_coverage_rules (tenant_id, plan_id, service_category)
              where effective_to is null'
        );

        Rls::enable('ins_plan_coverage_rules');
    }

    public function down(): void
    {
        Rls::disable('ins_plan_coverage_rules');
        Schema::dropIfExists('ins_plan_coverage_rules');
    }
};
