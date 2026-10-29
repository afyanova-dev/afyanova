<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Pharm.3c) — the Medication Pickup record
 * (16-pharmacy-domain.md §26, §29–§31, §47, §129; ADR-186 D-B).
 *
 * ONE Pickup per Dispensing (§47 — pickup-per-dispensing-event), **auto-created
 * `READY_FOR_PICKUP` in the `Dispense` transaction** (ADR-186 D-C). MUTABLE —
 * `Rls::enable` only, NOT append-only — the pickup has a lifecycle
 * (READY_FOR_PICKUP → PICKED_UP | NOT_COLLECTED | CANCELLED; NOT_COLLECTED ⇄
 * PICKED_UP). `status` is an open-coded string constant (the `PharmacyOrderStatus`
 * precedent). `recipient_name` / `verification_note` / `outcome_note` are free
 * text ROW-ONLY (ADR-136 — never in an event or audit metadata).
 *
 * The partial unique `(tenant_id, dispensing_id)` enforces the 1:1 link; the
 * `rx_pickups` row is the shared serialisation lock between `RecordPickup` /
 * `MarkPickupNotCollected` / `CancelPickup` and `ReverseDispensing` (ADR-186 D-E).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rx_pickups', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');                       // = the dispensing's facility

            $table->uuid('dispensing_id');                     // the 1:1 Dispensing link — plain uuid, NO FK
            $table->uuid('pharmacy_order_id');                 // denormalised
            $table->uuid('prescription_id');                   // denormalised
            $table->uuid('patient_id');                        // denormalised

            $table->string('pickup_number');                   // PKP-%08d, tenant-wide unique (ADR-115)

            $table->string('status')->default('READY_FOR_PICKUP'); // READY_FOR_PICKUP | PICKED_UP | NOT_COLLECTED | CANCELLED

            $table->string('recipient_type')->nullable();      // NULL until PICKED_UP; SET-validated (§30)
            $table->text('recipient_name')->nullable();        // ROW-ONLY free text — a representative's name (ADR-136)
            $table->text('verification_note')->nullable();     // ROW-ONLY — what identity check was done (§31)

            $table->uuid('collected_by_user_id')->nullable();  // NULL until PICKED_UP
            $table->timestampTz('collected_at')->nullable();

            $table->string('outcome_reason_code')->nullable(); // coded — the NOT_COLLECTED / CANCELLED reason (SET-validated)
            $table->text('outcome_note')->nullable();          // ROW-ONLY

            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'pickup_number']);    // ADR-115
            $table->index(['tenant_id', 'facility_id', 'status']); // the pickup queue
            $table->index(['tenant_id', 'pharmacy_order_id']);
        });

        // ADR-186 D-B — one Pickup per Dispensing.
        DB::statement(
            'create unique index rx_pickups_one_per_dispensing '.
            'on rx_pickups (tenant_id, dispensing_id)'
        );

        Rls::enable('rx_pickups');
    }

    public function down(): void
    {
        Rls::disable('rx_pickups');
        Schema::dropIfExists('rx_pickups');
    }
};
