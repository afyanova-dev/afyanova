<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped quotation-number counter (ADR-173 D-C / D-J — `QUO-%08d`, a
 * stable internal handle; the supplier's own reference is the free
 * `supplier_quote_ref` string). The ADR-115 / `pcr_po_number_counters`
 * precedent. Written only by `Infrastructure/Sequence/QuotationNumberSequence`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_quotation_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('pcr_quotation_number_counters');
    }

    public function down(): void
    {
        Rls::disable('pcr_quotation_number_counters');
        Schema::dropIfExists('pcr_quotation_number_counters');
    }
};
