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
        Schema::create('lab_test_methods', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('test_definition_id')->constrained('lab_test_definitions');

            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('specimen_type')->nullable();
            $table->string('container_type')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();

            $table->index(['tenant_id', 'test_definition_id']);
        });

        DB::statement(
            'create unique index lab_test_methods_tenant_test_code_active_unique '.
            'on lab_test_methods (tenant_id, test_definition_id, code) where active'
        );

        Rls::enable('lab_test_methods');

        Schema::create('lab_method_inventory_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('method_id')->constrained('lab_test_methods');
            $table->unsignedInteger('method_version');

            $table->uuid('inventory_item_id');
            $table->string('item_code_snapshot');
            $table->string('item_name_snapshot');
            $table->string('item_uom_snapshot');
            $table->string('item_type_snapshot');
            $table->string('item_category_snapshot')->nullable();
            $table->string('requirement_role');
            $table->boolean('required')->default(true);
            $table->string('usage_scope');
            $table->decimal('quantity', 18, 4);

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique([
                'tenant_id',
                'method_id',
                'method_version',
                'inventory_item_id',
            ], 'lab_method_inventory_requirements_snapshot_unique');
            $table->index(['tenant_id', 'method_id', 'method_version']);
        });

        Rls::enable('lab_method_inventory_requirements');
    }

    public function down(): void
    {
        Rls::disable('lab_method_inventory_requirements');
        Schema::dropIfExists('lab_method_inventory_requirements');

        Rls::disable('lab_test_methods');
        Schema::dropIfExists('lab_test_methods');
    }
};
