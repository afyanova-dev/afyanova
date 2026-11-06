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
        Schema::create('lab_facility_instruments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');
            $table->string('identifier', 100);
            $table->string('name', 200);
            $table->string('manufacturer', 200)->nullable();
            $table->string('model', 200)->nullable();
            $table->string('serial_number', 200)->nullable();
            $table->uuid('inventory_item_id')->nullable();
            $table->string('inventory_code_snapshot', 100)->nullable();
            $table->string('inventory_name_snapshot', 200)->nullable();
            $table->string('status', 30)->default('ACTIVE');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'identifier'], 'lab_facility_instruments_identity_unique');
            $table->index(['tenant_id', 'facility_id', 'status'], 'lab_facility_instruments_facility_status_index');
        });

        Rls::enable('lab_facility_instruments');

        Schema::create('lab_facility_instrument_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('instrument_id')->constrained('lab_facility_instruments');
            $table->unsignedBigInteger('instrument_version');
            $table->foreignUuid('method_id')->constrained('lab_test_methods');
            $table->unsignedInteger('method_version');
            $table->string('method_code_snapshot', 100);
            $table->string('method_name_snapshot', 200);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique([
                'tenant_id',
                'instrument_id',
                'instrument_version',
                'method_id',
            ], 'lab_instrument_methods_pair_version_unique');
            $table->index([
                'tenant_id',
                'instrument_id',
                'instrument_version',
            ], 'lab_instrument_methods_current_index');
            $table->index(['tenant_id', 'method_id', 'instrument_id'], 'lab_instrument_methods_method_index');
        });

        Rls::enable('lab_facility_instrument_methods');
    }

    public function down(): void
    {
        Rls::disable('lab_facility_instrument_methods');
        Schema::dropIfExists('lab_facility_instrument_methods');

        Rls::disable('lab_facility_instruments');
        Schema::dropIfExists('lab_facility_instruments');
    }
};
