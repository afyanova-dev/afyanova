<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.5) — leave types policy definition table
 * (25-hr-and-workforce-domain.md §6, §7.9; ADR-206).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_leave_types', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('code', 50);
            $table->string('name', 150);
            $table->boolean('is_paid')->default(true);
            $table->decimal('accrual_rate_per_year', 6, 2)->default(0.00);
            $table->decimal('max_balance', 6, 2)->nullable();
            $table->boolean('allow_negative')->default(false);
            $table->decimal('carry_over_limit', 6, 2)->nullable();
            $table->boolean('is_active')->default(true);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code'], 'hr_leave_types_tenant_code_unique');
        });

        Rls::enable('hr_leave_types');
    }

    public function down(): void
    {
        Rls::disable('hr_leave_types');
        Schema::dropIfExists('hr_leave_types');
    }
};
