<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 / P1-10 — close a purchase order short.
 *
 * The outstanding balance is abandoned with a reason; goods already received
 * stay in stock and the PO becomes terminal (`CLOSED_SHORT`). Kept separate
 * from `cancellation_reason` so reporting can tell a cancellation from a
 * short-closed order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pcr_purchase_orders', function (Blueprint $table): void {
            $table->text('closed_short_reason')->nullable();
            $table->foreignUuid('closed_short_by_user_id')->nullable();
            $table->timestampTz('closed_short_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pcr_purchase_orders', function (Blueprint $table): void {
            $table->dropColumn(['closed_short_reason', 'closed_short_by_user_id', 'closed_short_at']);
        });
    }
};
