<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 10 (increment Bill.3b) — the captured billable fact
 * (20-billing-and-payments-domain.md §10, §11, §126, §128; ADR-189 D-B).
 *
 * ADR-189 INV-4: a `pharmacy.MedicationDispensed` Bill.3 cannot turn into a
 * charge is CAPTURED as a PENDING row with a `pending_reason` — never dropped.
 * Mutable working record (`Rls::enable`; the status advances). Source-agnostic
 * shape — the other billable-event sources (Procedures / Lab / Radiology /
 * Theatre / bed-day) reuse it, each its own increment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_billable_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');

            $table->string('source_domain');                   // 'PHARMACY'
            $table->string('source_event_type');               // 'MedicationDispensed'
            $table->uuid('source_event_id');                   // the event's eventId (re-delivery idempotency)
            $table->uuid('source_ref');                        // the dispensing_id (the reversal links on this)

            $table->uuid('patient_id');
            $table->uuid('item_id');                           // an inv_items.id — plain uuid
            $table->decimal('quantity', 18, 4);

            $table->timestampTz('occurred_at');

            $table->string('status')->default('PENDING');      // PENDING | CHARGED | REVERSED | CANCELLED
            $table->string('pending_reason')->nullable();      // ITEM_UNMAPPED | SERVICE_UNPRICED | SERVICE_INACTIVE
            $table->uuid('charge_id')->nullable();             // the bill_charges row once CHARGED

            $table->timestampTz('recorded_at');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'source_event_id']);              // re-delivery idempotency (§128)
            $table->unique(['tenant_id', 'source_domain', 'source_ref']);  // C3 — one billable event per dispensing
            $table->index(['tenant_id', 'facility_id', 'status']);         // the pending worklist
        });

        Rls::enable('bill_billable_events');
    }

    public function down(): void
    {
        Rls::disable('bill_billable_events');
        Schema::dropIfExists('bill_billable_events');
    }
};
