<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.1) — staff employment aggregate root
 * (25-hr-and-workforce-domain.md §2, §6, §8; ADR-206).
 *
 * References a Person (human identity). Numbered STF-%08d.
 * Lifecycle: PROSPECT → ONBOARDING → ACTIVE ⇄ SUSPENDED → TERMINATED.
 * (No ON_LEAVE stored status; absence is derived from approved leave requests).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_staff', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('person_id')->index();
            $table->string('staff_number', 32);
            $table->string('status', 32);

            $table->uuid('primary_cadre_id')->nullable()->index();
            $table->uuid('primary_position_id')->nullable()->index();

            $table->date('joined_date')->nullable();
            $table->date('terminated_date')->nullable();
            $table->text('termination_reason')->nullable();
            $table->text('suspension_reason')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'staff_number']);
            $table->foreign('person_id')->references('id')->on('hr_persons')->cascadeOnDelete();
            $table->foreign('primary_cadre_id')->references('id')->on('hr_cadres')->nullOnDelete();
            $table->foreign('primary_position_id')->references('id')->on('hr_positions')->nullOnDelete();
        });

        Rls::enable('hr_staff');
    }

    public function down(): void
    {
        Rls::disable('hr_staff');
        Schema::dropIfExists('hr_staff');
    }
};
