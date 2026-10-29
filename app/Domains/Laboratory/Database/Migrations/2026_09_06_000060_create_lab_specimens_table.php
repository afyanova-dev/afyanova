<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laboratory specimens (14-laboratory-domain.md §28–§40, §150, §156, §223–§227;
 * ADR-158 D-D). A first-class laboratory concept — the physical sample drawn
 * for an order. Reuses the ADR-132 context+time shape.
 *
 * `order_id` is an intra-`lab_` FK (`->constrained('lab_orders')`). `patient_id`
 * is a SNAPSHOT of the order's patient — the wrong-patient safety control
 * (§224, §227): a specimen is never silently moved between patients
 * (Invariant 11). `accession_number` = `LABSP-%08d`, allocated at collection —
 * the laboratory PROCESSING identity, distinct from the order number (§33); one
 * order may produce more than one specimen / accession.
 *
 * `collected_by_user_id` / `received_by_user_id` are plain uuids, NO HR link
 * (the C.6 CareTeamMembership precedent — HR & Workforce is PHASE 11).
 * `specimen_type` / `container_type` / `collection_location` are open-coded
 * normalised uppercase (§29, §30, §38). `rejection_reason_code` is open-coded
 * (§34's list is illustrative, not a hard enum); `rejection_detail` is free
 * text, ROW-ONLY (never event / audit metadata — the ADR-136 deny-list).
 *
 * Lab.2 lifecycle (§156): COLLECTED → RECEIVED → ACCEPTED; COLLECTED|RECEIVED →
 * REJECTED. `PROCESSING` / `COMPLETED` are RESERVED for Lab.3a (result entry);
 * `EXPECTED` / `LOST` / `CANCELLED` are declared-not-wired in Lab.2.
 * `recollection_of_specimen_id` (nullable self-ref) makes a recollected
 * specimen traceable to the rejected original (§36).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_specimens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            $table->foreignUuid('order_id')->constrained('lab_orders');
            $table->uuid('patient_id'); // snapshot of the order's patient (wrong-patient safety, §224/§227)
            $table->string('accession_number'); // LABSP-%08d — the processing identity (§32–§33)

            $table->string('specimen_type');
            $table->string('container_type')->nullable();
            $table->string('collection_location')->nullable();

            $table->uuid('collected_by_user_id');
            $table->timestampTz('collected_at');
            $table->uuid('received_by_user_id')->nullable();
            $table->timestampTz('received_at')->nullable();

            $table->string('status')->default('COLLECTED');
            $table->string('rejection_reason_code')->nullable();
            $table->text('rejection_detail')->nullable();          // free text, row-only (ADR-136)
            $table->timestampTz('recollection_requested_at')->nullable();
            $table->uuid('recollection_of_specimen_id')->nullable(); // self-ref, no FK (§36)

            $table->uuid('created_by_user_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'accession_number']);
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'patient_id']);
        });

        Rls::enable('lab_specimens');
    }

    public function down(): void
    {
        Rls::disable('lab_specimens');
        Schema::dropIfExists('lab_specimens');
    }
};
