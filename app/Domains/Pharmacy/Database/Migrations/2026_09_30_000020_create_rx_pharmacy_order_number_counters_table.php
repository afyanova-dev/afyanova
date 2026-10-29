<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Pharm.2a) — tenant-scoped pharmacy-order-number counter
 * (ADR-115; ADR-182 D-B).
 *
 * One row per tenant (`UNIQUE (tenant_id)`). Allocated by
 * App\Domains\Pharmacy\Infrastructure\Sequence\PharmacyOrderNumberSequence
 * inside the CreatePharmacyOrder command transaction. The
 * `rx_prescription_number_counters` / `enc_encounter_number_counters` shape
 * verbatim — id uuid primary, tenant_id indexed, `version` bumped by the manual
 * UPDATE, timestampsTz, Rls::enable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rx_pharmacy_order_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id'); // ADR-115 — one counter row per tenant
        });

        Rls::enable('rx_pharmacy_order_number_counters');
    }

    public function down(): void
    {
        Rls::disable('rx_pharmacy_order_number_counters');
        Schema::dropIfExists('rx_pharmacy_order_number_counters');
    }
};
