<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Pharm.2a) — the Pharmacy Order + the pharmacist validation
 * workflow (16-pharmacy-domain.md §21, §22, §106, §122, §254; ADR-182 D-B).
 *
 * A distinct aggregate from the Pharm.1 Prescription record (§21 — Prescription
 * = the authoritative record, Pharmacy Order = the operational fulfilment task).
 * Carries the §249 / §122 workflow states ADR-181 C2 deferred "with the Pharmacy
 * Order aggregate": RECEIVED → VALIDATED | REJECTED, + ON_HOLD → RECEIVED,
 * + CANCELLED. `READY` / dispensing states + the reservation / consumption
 * cross-domain work are Pharm.3.
 *
 * Mutable working record — `Rls::enable` only, NOT append-only. Facility-scoped
 * to the prescription's ORIGINATING facility (Pharm.1 D-H; §81 cross-facility
 * fulfilment is deferred). `status` is an open-coded string constant (the
 * `StockAdjustment` precedent). `reason_note` free text is ROW-ONLY (ADR-136).
 * One non-terminal order per prescription — the partial unique index (the
 * ADR-121 `enc_one_active_per_patient_facility` precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rx_pharmacy_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                       // = the prescription's ORIGINATING facility

            $table->uuid('prescription_id');                   // the Pharm.1 link — plain uuid, NO FK
            $table->uuid('patient_id');                        // denormalised from the prescription — the queue

            $table->string('order_number');                    // RXO-%08d, tenant-wide unique (ADR-115)
            $table->uuid('dispensing_location_id')->nullable(); // an inv_stock_locations id (§71/§72); NOT validated in 2a
            $table->string('priority')->default('ROUTINE');    // ROUTINE | URGENT | STAT — behaviour-neutral (§22)

            $table->string('status')->default('RECEIVED');     // RECEIVED | VALIDATED | REJECTED | ON_HOLD | CANCELLED
            $table->uuid('validated_by_user_id')->nullable();
            $table->string('reject_reason_code')->nullable();  // §124
            $table->string('hold_reason_code')->nullable();    // §123
            $table->string('cancel_reason_code')->nullable();  // §58
            $table->text('reason_note')->nullable();           // ROW-ONLY (ADR-136) — the current reject/hold/cancel note
            $table->uuid('cancelled_by_user_id')->nullable();

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'order_number']);     // ADR-115
            $table->index(['tenant_id', 'facility_id', 'status']); // the §186 queue
            $table->index(['tenant_id', 'patient_id']);
        });

        // ADR-182 D-B — one non-terminal Pharmacy Order per prescription.
        DB::statement(
            "create unique index rx_pharmacy_orders_one_open_per_prescription
                 on rx_pharmacy_orders (tenant_id, prescription_id)
              where status not in ('REJECTED', 'CANCELLED')"
        );

        Rls::enable('rx_pharmacy_orders');
    }

    public function down(): void
    {
        Rls::disable('rx_pharmacy_orders');
        Schema::dropIfExists('rx_pharmacy_orders');
    }
};
