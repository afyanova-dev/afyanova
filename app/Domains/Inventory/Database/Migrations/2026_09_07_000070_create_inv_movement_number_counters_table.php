<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped stock-movement-number counter (ADR-170 D-K — `MOV-%08d`, the
 * ADR-115 / `rad_order_number_counters` / `lab_order_number_counters` precedent,
 * same class shape). §50's `MOV-2026-000123` year form is illustrative (the
 * Rad.2 `RAD-` precedent).
 *
 * The IC §10 tenant-only shape — not part of any aggregate. Written only by
 * `Infrastructure/Sequence/MovementNumberSequence`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_movement_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('inv_movement_number_counters');
    }

    public function down(): void
    {
        Rls::disable('inv_movement_number_counters');
        Schema::dropIfExists('inv_movement_number_counters');
    }
};
