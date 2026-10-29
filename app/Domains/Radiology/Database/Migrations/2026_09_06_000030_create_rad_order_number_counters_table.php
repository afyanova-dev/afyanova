<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped imaging-order-number counter (ADR-165 D-E — `RAD-%08d`, the
 * ADR-115 / `lab_order_number_counters` / `thr_case_number_counters` precedent,
 * same class shape).
 *
 * Same IC §10 tenant-only shape — not part of the `ImagingOrder` aggregate.
 * Written only by `Infrastructure/Sequence/OrderNumberSequence`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rad_order_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('rad_order_number_counters');
    }

    public function down(): void
    {
        Rls::disable('rad_order_number_counters');
        Schema::dropIfExists('rad_order_number_counters');
    }
};
