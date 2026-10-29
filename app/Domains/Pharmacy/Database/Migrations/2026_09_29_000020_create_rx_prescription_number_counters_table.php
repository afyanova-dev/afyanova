<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Pharm.1) — tenant-scoped prescription-number counter
 * (ADR-115; ADR-181 D-C).
 *
 * Exactly one row per tenant (`UNIQUE (tenant_id)`). Not part of the Prescription
 * aggregate; allocated by
 * App\Domains\Pharmacy\Infrastructure\Sequence\PrescriptionNumberSequence inside
 * the IngestPrescription command transaction. The `enc_encounter_number_counters`
 * / `pcr_*_number_counters` shape verbatim — id uuid primary, tenant_id indexed,
 * `version` bumped by the manual UPDATE (not HasVersionColumn — the counter is
 * not an aggregate), timestampsTz, Rls::enable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rx_prescription_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id'); // ADR-115 — one counter row per tenant
        });

        Rls::enable('rx_prescription_number_counters');
    }

    public function down(): void
    {
        Rls::disable('rx_prescription_number_counters');
        Schema::dropIfExists('rx_prescription_number_counters');
    }
};
