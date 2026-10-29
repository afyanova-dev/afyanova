<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 12 (Increment P12.1) — Queueing & Patient Flow Platform Service
 * Operational Patient Movements & Location Transitions table (23-queueing-and-patient-flow.md §18, §19, §310, §315; ADR-130 B).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qms_patient_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->uuid('encounter_id')->index(); // Logical ref to enc_encounters (no cross-prefix FK, ADR-093)
            $table->uuid('patient_id')->index();   // Logical ref to pat_patients (no cross-prefix FK, ADR-093)

            $table->string('from_area')->nullable(); // e.g. 'RECEPTION', 'TRIAGE', 'OPD_ROOM_2'
            $table->string('to_area');               // e.g. 'LABORATORY', 'PHARMACY', 'CONSULTATION'
            $table->string('status', 32)->default('ARRIVED')->index(); // REQUESTED, IN_TRANSIT, ARRIVED, CANCELLED

            $table->uuid('queue_entry_id')->nullable()->index(); // Logical ref to qms_queue_entries
            $table->uuid('actor_id')->nullable()->index();       // Staff/user who recorded movement (no cross-prefix FK, ADR-093)
            $table->string('reason')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->foreign('queue_entry_id')
                ->references('id')
                ->on('qms_queue_entries')
                ->nullOnDelete();

            $table->index(['tenant_id', 'facility_id', 'encounter_id'], 'qms_movements_encounter_idx');
            $table->index(['tenant_id', 'occurred_at'], 'qms_movements_occurred_at_idx');
        });

        Rls::enable('qms_patient_movements');
    }

    public function down(): void
    {
        Rls::disable('qms_patient_movements');
        Schema::dropIfExists('qms_patient_movements');
    }
};
