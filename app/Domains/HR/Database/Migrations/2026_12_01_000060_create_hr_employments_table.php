<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.1) — employment contracts aggregate root
 * (25-hr-and-workforce-domain.md §3, §6, §8; ADR-206).
 *
 * Lifecycle: DRAFT → ACTIVE ⇄ SUSPENDED → ENDED.
 *
 * Invariant 7.4: At most one ACTIVE employment per staff member, enforced
 * via database partial unique index hr_employments_one_active_per_staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('staff_id')->index();
            $table->uuid('position_id')->index();

            $table->string('contract_type', 32);
            $table->string('status', 32);

            $table->decimal('fte', 4, 2)->default(1.00);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('probation_end_date')->nullable();
            $table->unsignedInteger('notice_period_days')->default(30);
            $table->text('terms_summary')->nullable();

            $table->text('suspension_reason')->nullable();
            $table->text('end_reason')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('staff_id')->references('id')->on('hr_staff')->cascadeOnDelete();
            $table->foreign('position_id')->references('id')->on('hr_positions')->cascadeOnDelete();
        });

        DB::statement(
            "CREATE UNIQUE INDEX hr_employments_one_active_per_staff
             ON hr_employments (tenant_id, staff_id)
             WHERE status = 'ACTIVE'"
        );

        Rls::enable('hr_employments');
    }

    public function down(): void
    {
        Rls::disable('hr_employments');
        DB::statement('DROP INDEX IF EXISTS hr_employments_one_active_per_staff');
        Schema::dropIfExists('hr_employments');
    }
};
