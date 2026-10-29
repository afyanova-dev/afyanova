<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.2) — organizational assignments aggregate root
 * (25-hr-and-workforce-domain.md §3, §6, §7, §8; Invariant 7.5, 7.7).
 *
 * Scope: FACILITY, DEPARTMENT, UNIT.
 * Lifecycle: ACTIVE -> ENDED.
 *
 * Invariant 7.5: Carries ZERO RBAC permissions or roles.
 * Invariant 7.7: References facility/department/unit owned by Organization; HR never creates org structure.
 * At most one ACTIVE primary assignment per staff member, enforced via database partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('staff_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('department_id')->nullable()->index();
            $table->uuid('unit_id')->nullable()->index();

            $table->string('scope', 32);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 32);
            $table->text('end_reason')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('staff_id')->references('id')->on('hr_staff')->cascadeOnDelete();
        });

        DB::statement(
            "CREATE UNIQUE INDEX hr_assignments_one_primary_active
             ON hr_assignments (tenant_id, staff_id)
             WHERE is_primary = true AND status = 'ACTIVE'"
        );

        Rls::enable('hr_assignments');
    }

    public function down(): void
    {
        Rls::disable('hr_assignments');
        DB::statement('DROP INDEX IF EXISTS hr_assignments_one_primary_active');
        Schema::dropIfExists('hr_assignments');
    }
};
