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
        Schema::create('inv_stock_location_types', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->string('code', 100);
            $table->string('name', 200);
            $table->uuid('created_by');
            $table->unsignedInteger('version')->default(0);
            $table->timestampsTz();
        });

        DB::statement(
            'create unique index inv_stock_location_types_tenant_code_unique '.
            'on inv_stock_location_types (tenant_id, code) where facility_id is null'
        );
        DB::statement(
            'create unique index inv_stock_location_types_facility_code_unique '.
            'on inv_stock_location_types (tenant_id, facility_id, code) where facility_id is not null'
        );

        Rls::enable('inv_stock_location_types');
    }

    public function down(): void
    {
        Rls::disable('inv_stock_location_types');
        Schema::dropIfExists('inv_stock_location_types');
    }
};
