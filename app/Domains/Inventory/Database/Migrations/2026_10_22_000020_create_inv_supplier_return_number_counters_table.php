<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_supplier_return_number_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->bigInteger('next_value')->default(1);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique('tenant_id');
        });

        Rls::enable('inv_supplier_return_number_counters');
    }

    public function down(): void
    {
        Rls::disable('inv_supplier_return_number_counters');
        Schema::dropIfExists('inv_supplier_return_number_counters');
    }
};
