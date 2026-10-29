<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 12 (Increment P12.1) — Queueing & Patient Flow Platform Service
 * Operational Queue Entries (Tickets) table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qms_queue_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->uuid('queue_id')->index();
            $table->uuid('encounter_id')->index(); // Logical ref to enc_encounters (no cross-prefix FK, ADR-093)
            $table->uuid('patient_id')->index();   // Logical ref to pat_patients (no cross-prefix FK, ADR-093)

            $table->string('ticket_number', 32);   // e.g. 'A-001', 'C-014'
            $table->string('priority', 32)->default('ROUTINE')->index(); // ROUTINE, PRIORITY, URGENT, EMERGENCY (as supplied)
            $table->string('status', 32)->default('WAITING')->index();   // WAITING, CALLED, IN_SERVICE, HOLD, COMPLETED, NO_RESPONSE, EXPIRED

            $table->string('hold_reason', 50)->nullable()->index(); // FINANCIAL_HOLD, CLINICAL_HOLD, ADMINISTRATIVE_HOLD
            $table->string('hold_provenance', 255)->nullable();     // Event or domain ref authorizing hold
            $table->string('clearance_provenance', 255)->nullable(); // Clearance or payment ref authorizing hold release

            $table->uuid('source_order_ref')->nullable()->index();  // Required for diagnostic/dispensing queues
            $table->string('order_type', 50)->nullable();           // LAB_ORDER, PRESCRIPTION, RADIOLOGY_REQUEST, PROCEDURE_BOOKING

            $table->timestampTz('called_at')->nullable();
            $table->uuid('called_room_id')->nullable()->index();    // Logical ref to org_rooms (no cross-prefix FK, ADR-093)
            $table->uuid('called_staff_id')->nullable()->index();   // Logical ref to hr_staff (no cross-prefix FK, ADR-093)

            $table->timestampTz('service_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedInteger('call_attempts')->default(0);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('queue_id')->references('id')->on('qms_queues')->cascadeOnDelete();
            $table->index(['tenant_id', 'facility_id', 'queue_id', 'status', 'priority'], 'qms_queue_entries_calling_idx');
            $table->index(['tenant_id', 'encounter_id', 'status'], 'qms_queue_entries_encounter_status_idx');
        });

        Rls::enable('qms_queue_entries');
    }

    public function down(): void
    {
        Rls::disable('qms_queue_entries');
        Schema::dropIfExists('qms_queue_entries');
    }
};
