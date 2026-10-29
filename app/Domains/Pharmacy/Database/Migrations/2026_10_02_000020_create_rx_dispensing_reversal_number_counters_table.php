<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 9 (increment Pharm.3b) — tenant-scoped dispensing-reversal-number
 * counter (ADR-115; ADR-185 D-B). `DRV-%08d`. The
 * `rx_dispensing_number_counters` shape verbatim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rx_dispensing_reversal_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id'); // ADR-115 — one counter row per tenant
        });

        Rls::enable('rx_dispensing_reversal_number_counters');
    }

    public function down(): void
    {
        Rls::disable('rx_dispensing_reversal_number_counters');
        Schema::dropIfExists('rx_dispensing_reversal_number_counters');
    }
};
