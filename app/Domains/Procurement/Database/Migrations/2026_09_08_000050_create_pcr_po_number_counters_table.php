<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped purchase-order-number counter (ADR-171 D-G — `PO-%08d`; §46's
 * `PO-2026-001245` year form is illustrative, the Inv.1 `MOV-` precedent).
 * Written only by `Infrastructure/Sequence/PurchaseOrderNumberSequence`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_po_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('pcr_po_number_counters');
    }

    public function down(): void
    {
        Rls::disable('pcr_po_number_counters');
        Schema::dropIfExists('pcr_po_number_counters');
    }
};
