<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (Shape B) — per-facility / per-department single-actor approval
 * limits (19-procurement-domain.md §24, §45, §228).
 *
 * The §24 / §228 different-actor guards assume three people per order. A small
 * private hospital cannot staff that, so an order whose total is at or below
 * the effective limit may be approved and issued by one person. A single
 * deployment-wide number cannot express reality: a dispensary, a health centre
 * and a hospital buy at completely different scales, and departments differ too.
 *
 * ONE row per scope:
 *   - `department_ref IS NULL` → the facility-wide limit (at most one per
 *     facility — partial unique index below);
 *   - `department_ref IS NOT NULL` → a department override (at most one per
 *     facility + department), matched against the requisition's
 *     `requesting_department_ref` (an opaque Organization department id — §224,
 *     no FK, so Procurement never reads across the boundary).
 *
 * Authored via `procurement:configure-approval-limit` (audited). A scope with
 * NO row falls back to `config('procurement.approval.facility_type_defaults')`
 * and finally to `0` (off) — see `ApprovalLimitResolver`. Mutable →
 * `Rls::enable`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_approval_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');
            $table->uuid('department_ref')->nullable();       // opaque — no FK (§224)

            $table->decimal('self_approval_limit', 18, 2);    // Money
            $table->string('currency')->default('TZS');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'facility_id']);
        });

        // At most one facility-wide row per facility (ary NULLs would otherwise
        // permit duplicates — a NULL is distinct in a plain unique index).
        DB::statement(
            'create unique index pcr_approval_policies_one_facility_default
                 on pcr_approval_policies (tenant_id, facility_id)
              where department_ref is null'
        );

        // At most one override per (facility, department).
        DB::statement(
            'create unique index pcr_approval_policies_one_per_department
                 on pcr_approval_policies (tenant_id, facility_id, department_ref)
              where department_ref is not null'
        );

        Rls::enable('pcr_approval_policies');
    }

    public function down(): void
    {
        Rls::disable('pcr_approval_policies');
        Schema::dropIfExists('pcr_approval_policies');
    }
};
