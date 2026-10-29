<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 8 (stock counts) — one line of a stock count: one (item, batch) the
 * system believes is at the counted location.
 *
 * `system_qty` is a SNAPSHOT of the on-hand quantity when the count started —
 * a fixed reference, not a live balance. `counted_qty` stays NULL until the
 * counter writes down what they found; the difference is the variance. A NULL
 * counted quantity means "not counted", NOT zero: only a deliberately counted
 * line produces a variance.
 *
 * Adding an item the sheet does not already list (a physical find the system
 * did not know about) is allowed and snapshots the current (usually zero)
 * on-hand so the variance reads correctly. `note` is free text, ROW-ONLY
 * (ADR-136).
 *
 * The unique index mirrors `inv_stock_balances_identity`: one line per
 * (count, item, batch), with NULL batches treated as equal (NULLS NOT
 * DISTINCT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_count_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->foreignUuid('stock_count_id')->constrained('inv_stock_counts')->cascadeOnDelete();
            $table->foreignUuid('item_id')->constrained('inv_items');
            $table->foreignUuid('batch_id')->nullable()->constrained('inv_batches');

            $table->decimal('system_qty', 18, 4)->default(0);   // snapshot at count start
            $table->decimal('counted_qty', 18, 4)->nullable();  // NULL = not counted yet

            $table->text('note')->nullable();                   // ROW-ONLY (ADR-136)
            $table->uuid('counted_by_user_id')->nullable();
            $table->timestampTz('counted_at')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'stock_count_id']);
        });

        DB::statement('alter table inv_stock_count_lines add constraint inv_stock_count_lines_system_qty check (system_qty >= 0)');
        DB::statement('alter table inv_stock_count_lines add constraint inv_stock_count_lines_counted_qty check (counted_qty is null or counted_qty >= 0)');
        DB::statement('create unique index inv_stock_count_lines_identity on inv_stock_count_lines (stock_count_id, item_id, batch_id) nulls not distinct');

        Rls::enable('inv_stock_count_lines');
    }

    public function down(): void
    {
        Rls::disable('inv_stock_count_lines');
        Schema::dropIfExists('inv_stock_count_lines');
    }
};
