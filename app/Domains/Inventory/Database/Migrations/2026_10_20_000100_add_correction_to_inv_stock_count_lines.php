<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 8 (stock counts, Step 2) — record what happened to each difference when
 * the count was completed.
 *
 * A count still never edits a balance. On completion, each counted line whose
 * quantity disagrees with the snapshot raises a PENDING_APPROVAL stock
 * adjustment under `reason_code = COUNT_VARIANCE`; the approval is the moment
 * stock moves, and a second person must make it.
 *
 * `variance_adjustment_id` links the line to the adjustment it raised, so the
 * sheet can show "awaiting approval" or "approved" without guessing.
 * `variance_skipped_reason` records the two honest reasons a difference was NOT
 * raised automatically: the stock moved between the snapshot and completion
 * (the difference cannot be attributed cleanly), or the person completing the
 * count may not raise adjustments.
 *
 * Both are nullable — no difference, or a line that was never counted, leaves
 * them empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_count_lines', function (Blueprint $table): void {
            $table->foreignUuid('variance_adjustment_id')
                ->nullable()
                ->constrained('inv_stock_adjustments');
            $table->string('variance_skipped_reason', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inv_stock_count_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('variance_adjustment_id');
            $table->dropColumn('variance_skipped_reason');
        });
    }
};
