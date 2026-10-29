<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped laboratory-order-number counter (ADR-157 D-D — `LAB-%08d`, the
 * ADR-115 / `thr_case_number_counters` precedent, same class shape).
 *
 * Same IC §10 tenant-only shape as `thr_case_number_counters` /
 * `enc_encounter_number_counters` — not part of the `LabOrder` aggregate.
 * Written only by `Infrastructure/Sequence/OrderNumberSequence`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_order_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('lab_order_number_counters');
    }

    public function down(): void
    {
        Rls::disable('lab_order_number_counters');
        Schema::dropIfExists('lab_order_number_counters');
    }
};
