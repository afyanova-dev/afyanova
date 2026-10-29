<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 12 (Increment P12.1) — Queueing & Patient Flow Platform Service
 * Logical Queues definition table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qms_queues', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('code', 50); // e.g. 'TRIAGE', 'OPD_GP', 'CASHIER', 'PHARMACY_MAIN', 'LAB_SPECIMEN'
            $table->string('name', 100);
            $table->uuid('department_id')->nullable()->index(); // Logical ref to org_departments (no cross-prefix FK, ADR-093)
            $table->string('service_capability', 50)->nullable()->index(); // 'TRIAGE', 'CONSULTATION', 'PHARMACY', 'LABORATORY', etc.
            $table->string('ticket_prefix', 5)->default('A');
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'code'], 'qms_queues_tenant_facility_code_unique');
        });

        Rls::enable('qms_queues');
    }

    public function down(): void
    {
        Rls::disable('qms_queues');
        Schema::dropIfExists('qms_queues');
    }
};
