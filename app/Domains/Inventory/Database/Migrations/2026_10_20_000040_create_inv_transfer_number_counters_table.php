<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped stock-transfer-number counter (Phase 8 — `TRF-%08d`), the
 * `inv_movement_number_counters` shape. Written only by
 * `Infrastructure/Sequence/TransferNumberSequence`, inside the command
 * transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_transfer_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('inv_transfer_number_counters');
    }

    public function down(): void
    {
        Rls::disable('inv_transfer_number_counters');
        Schema::dropIfExists('inv_transfer_number_counters');
    }
};
