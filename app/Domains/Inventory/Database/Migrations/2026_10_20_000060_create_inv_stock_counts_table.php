<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 8 (stock counts) — a physical stock count at ONE location.
 *
 * A count is a session, not a movement: it records what the shelf actually
 * holds and compares it with what the system believes. Starting a count
 * snapshots the location's balances into `inv_stock_count_lines`; the counter
 * enters the quantity found on each line and the sheet shows the difference.
 *
 * The count NEVER edits a stock balance. A variance is put right later through
 * the existing two-person stock adjustment (a decrease needs a second approver,
 * and a controlled medicine needs a named witness) — the same rule the expiry
 * and disposal flows already follow. That keeps "count" and "correct" as two
 * separate jobs done by two separate people.
 *
 * `notes` is free text, ROW-ONLY (the ADR-136 deny-list).
 *
 *   IN_PROGRESS → COMPLETED | DISCARDED
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_counts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();   // = the location's facility

            $table->string('count_number')->unique(); // CNT-%08d

            $table->foreignUuid('location_id')->constrained('inv_stock_locations');

            $table->string('status')->default('IN_PROGRESS'); // IN_PROGRESS | COMPLETED | DISCARDED
            $table->text('notes')->nullable();                // ROW-ONLY (ADR-136)

            $table->uuid('started_by_user_id');
            $table->timestampTz('started_at');
            $table->timestampTz('closed_at')->nullable(); // when it stopped being IN_PROGRESS

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'facility_id', 'started_at']);
            $table->index(['tenant_id', 'location_id', 'status']);
        });

        DB::statement("alter table inv_stock_counts add constraint inv_stock_counts_status check (status in ('IN_PROGRESS', 'COMPLETED', 'DISCARDED'))");

        // One open count per location, even if two people click Start at the
        // same moment: the handler's friendly check is the normal path, this
        // index is the backstop. Closing/discarding frees the location because
        // the row leaves IN_PROGRESS.
        DB::statement("create unique index inv_stock_counts_one_open_per_location on inv_stock_counts (location_id) where status = 'IN_PROGRESS'");

        Rls::enable('inv_stock_counts');
    }

    public function down(): void
    {
        Rls::disable('inv_stock_counts');
        Schema::dropIfExists('inv_stock_counts');
    }
};
