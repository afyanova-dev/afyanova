<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped contract-number counter (ADR-176 D-B — `CON-%08d`, the
 * ADR-115 / `pcr_rfq_number_counters` precedent, same shape). Written only by
 * `Infrastructure/Sequence/ContractNumberSequence`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pcr_contract_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique('tenant_id');
        });

        Rls::enable('pcr_contract_number_counters');
    }

    public function down(): void
    {
        Rls::disable('pcr_contract_number_counters');
        Schema::dropIfExists('pcr_contract_number_counters');
    }
};
