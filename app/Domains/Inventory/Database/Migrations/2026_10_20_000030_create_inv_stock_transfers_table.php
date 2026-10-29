<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 8 (stock transfers) — moving stock between two locations of the same
 * facility (for example main store → pharmacy counter).
 *
 * A transfer is ONE record with TWO ledger sides: a `TRANSFER_OUT` movement at
 * the source and a `TRANSFER_IN` movement at the destination, written in the
 * same transaction. The two `inv_stock_movements` rows keep the ledger honest
 * (`balance = Σ movements` still holds per location), and this row is the
 * document that ties them together — its number can be written on the paper
 * transfer note the two stores sign.
 *
 * A transfer never changes the facility's total stock and never needs a second
 * approver: nothing is gained or lost. It still cannot move more than the
 * source location has available, and reserved stock stays where it is.
 *
 * `from_location_id` and `to_location_id` must differ (CHECK). Both belong to
 * `facility_id`; the handler refuses a cross-facility move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_stock_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('transfer_number')->unique(); // TRF-%08d

            $table->foreignUuid('item_id')->constrained('inv_items');
            $table->foreignUuid('from_location_id')->constrained('inv_stock_locations');
            $table->foreignUuid('to_location_id')->constrained('inv_stock_locations');
            $table->foreignUuid('batch_id')->nullable()->constrained('inv_batches');

            $table->decimal('quantity', 18, 4);        // POSITIVE, base unit
            $table->text('reason_text')->nullable();   // ROW-ONLY (ADR-136)

            $table->foreignUuid('out_movement_id')->constrained('inv_stock_movements');
            $table->foreignUuid('in_movement_id')->constrained('inv_stock_movements');

            $table->uuid('moved_by_user_id');
            $table->timestampTz('moved_at');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'facility_id', 'moved_at']);
            $table->index(['tenant_id', 'item_id', 'from_location_id']);
            $table->index(['tenant_id', 'to_location_id']);
        });

        DB::statement('alter table inv_stock_transfers add constraint inv_stock_transfers_qty_positive check (quantity > 0)');
        DB::statement('alter table inv_stock_transfers add constraint inv_stock_transfers_locations_differ check (from_location_id <> to_location_id)');

        Rls::enable('inv_stock_transfers');
    }

    public function down(): void
    {
        Rls::disable('inv_stock_transfers');
        Schema::dropIfExists('inv_stock_transfers');
    }
};
