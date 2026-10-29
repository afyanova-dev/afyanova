<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Surgical team assignments (17-theatre-domain.md §41-45; ADR-150 D-C/D-F).
 *
 * Plain child table — NOT an AggregateRoot (the domain doc names no
 * team-assignment event, the Room precedent from ADR-148 D-A). staff_user_id
 * is a plain uuid, no HR link (the C.6 CareTeamMembership precedent — HR &
 * Workforce is PHASE 11). replaced_at (nullable) satisfies §45's "Staff
 * Assignment History" via append-and-mark-replaced, never a hard delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thr_case_team_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('case_id');
            $table->uuid('staff_user_id');
            $table->string('role');

            $table->timestampTz('assigned_at');
            $table->timestampTz('replaced_at')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'case_id']);
            $table->index(['tenant_id', 'facility_id']);
        });

        Rls::enable('thr_case_team_assignments');
    }

    public function down(): void
    {
        Rls::disable('thr_case_team_assignments');
        Schema::dropIfExists('thr_case_team_assignments');
    }
};
