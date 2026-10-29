<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controlled-medicine handling (Phase 8, second slice; the Phase 0 disposition
 * policy's "destruction witness required for controlled drugs").
 *
 * `inv_items.is_controlled` marks a catalog entry as a controlled medicine
 * (narcotic / psychotropic / otherwise special-handling). It exists so the
 * system can *tell* which removals need an independent witness instead of
 * asking staff to remember — the flag is a plain boolean because the schedule
 * classification itself (TMDA/NEMLIT) is deferred with the rest of Inv.0.
 *
 * `inv_stock_adjustments.witness_name` records who watched a removal of
 * controlled stock. It is ROW-ONLY (ADR-136): a person's name, never an event
 * payload. It is required by the domain when a DECREASE adjustment targets a
 * controlled item (`ControlledStockWitnessRequired`); for every other item it
 * stays null and free text is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_items', function (Blueprint $table): void {
            $table->boolean('is_controlled')->default(false);
        });

        Schema::table('inv_stock_adjustments', function (Blueprint $table): void {
            $table->string('witness_name', 120)->nullable(); // ROW-ONLY (ADR-136)
        });
    }

    public function down(): void
    {
        Schema::table('inv_stock_adjustments', function (Blueprint $table): void {
            $table->dropColumn('witness_name');
        });

        Schema::table('inv_items', function (Blueprint $table): void {
            $table->dropColumn('is_controlled');
        });
    }
};
