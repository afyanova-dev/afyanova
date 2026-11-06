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
        Schema::table('lab_orders', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'lab_orders_tenant_id_unique');
        });
        Schema::table('lab_order_items', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'order_id', 'id'], 'lab_order_items_tenant_order_item_unique');
        });
        Schema::table('lab_specimens', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'order_id', 'id'], 'lab_specimens_tenant_order_specimen_unique');
        });

        Schema::create('lab_executions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id');
            $table->uuid('order_id');
            $table->uuid('order_item_id');
            $table->uuid('specimen_id');
            $table->unsignedInteger('attempt_number');
            $table->foreignUuid('test_definition_id')->constrained('lab_test_definitions');
            $table->string('test_code_snapshot', 100);
            $table->string('test_name_snapshot', 200);
            $table->unsignedInteger('test_definition_version');
            $table->foreignUuid('method_id')->constrained('lab_test_methods');
            $table->string('method_code_snapshot', 100);
            $table->string('method_name_snapshot', 200);
            $table->unsignedInteger('method_version');
            $table->uuid('instrument_id')->nullable();
            $table->string('instrument_identifier_snapshot', 100)->nullable();
            $table->unsignedBigInteger('instrument_version')->nullable();
            $table->string('status', 30)->default('IN_PROGRESS');
            $table->uuid('started_by_user_id');
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('aborted_at')->nullable();
            $table->text('abort_reason')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'lab_executions_tenant_id_unique');
            $table->unique(
                ['tenant_id', 'order_item_id', 'attempt_number'],
                'lab_executions_item_attempt_unique',
            );
            $table->index(
                ['tenant_id', 'facility_id', 'status', 'started_at'],
                'lab_executions_facility_status_index',
            );
            $table->index(['tenant_id', 'order_id'], 'lab_executions_order_index');
        });
        DB::statement(
            'alter table lab_executions add constraint lab_executions_specimen_order_fk '.
            'foreign key (tenant_id, order_id, specimen_id) '.
            'references lab_specimens (tenant_id, order_id, id)'
        );
        DB::statement(
            'alter table lab_executions add constraint lab_executions_item_order_fk '.
            'foreign key (tenant_id, order_id, order_item_id) '.
            'references lab_order_items (tenant_id, order_id, id)'
        );
        DB::statement(
            'alter table lab_executions add constraint lab_executions_order_tenant_fk '.
            'foreign key (tenant_id, order_id) references lab_orders (tenant_id, id)'
        );

        DB::statement(
            'create unique index lab_executions_one_active_per_item_unique '.
            "on lab_executions (tenant_id, order_item_id) where status = 'IN_PROGRESS'"
        );
        Rls::enable('lab_executions');

        Schema::create('lab_execution_material_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('execution_id');
            $table->uuid('method_requirement_id');
            $table->unsignedInteger('method_version');
            $table->uuid('inventory_item_id');
            $table->string('item_code_snapshot', 100);
            $table->string('item_name_snapshot', 200);
            $table->string('item_uom_snapshot', 50);
            $table->string('item_type_snapshot', 50);
            $table->string('item_category_snapshot', 100)->nullable();
            $table->string('requirement_role', 30);
            $table->boolean('required')->default(true);
            $table->string('usage_scope', 30);
            $table->decimal('quantity', 18, 4);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(
                ['tenant_id', 'execution_id', 'id'],
                'lab_execution_requirements_tenant_execution_id_unique',
            );
            $table->foreign(['tenant_id', 'execution_id'])
                ->references(['tenant_id', 'id'])
                ->on('lab_executions')
                ->cascadeOnDelete();
            $table->unique(
                ['tenant_id', 'execution_id', 'method_requirement_id'],
                'lab_execution_requirements_snapshot_unique',
            );
            $table->index(
                ['tenant_id', 'execution_id'],
                'lab_execution_requirements_execution_index',
            );
        });
        Rls::enable('lab_execution_material_requirements');

        Schema::create('lab_execution_material_uses', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('execution_id');
            $table->uuid('requirement_id');
            $table->unsignedInteger('attempt_number');
            $table->uuid('movement_id');
            $table->uuid('inventory_item_id');
            $table->uuid('location_id');
            $table->string('batch_number', 100)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->string('status', 30)->default('PENDING');
            $table->text('failure_message')->nullable();
            $table->uuid('recorded_by_user_id');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'execution_id', 'requirement_id'])
                ->references(['tenant_id', 'execution_id', 'id'])
                ->on('lab_execution_material_requirements')
                ->cascadeOnDelete();
            $table->unique(
                ['tenant_id', 'requirement_id', 'attempt_number'],
                'lab_execution_material_use_attempt_unique',
            );
            $table->unique(['tenant_id', 'movement_id'], 'lab_execution_material_use_movement_unique');
            $table->index(
                ['tenant_id', 'execution_id', 'status'],
                'lab_execution_material_uses_execution_index',
            );
        });
        Rls::enable('lab_execution_material_uses');
    }

    public function down(): void
    {
        Rls::disable('lab_execution_material_uses');
        Schema::dropIfExists('lab_execution_material_uses');

        Rls::disable('lab_execution_material_requirements');
        Schema::dropIfExists('lab_execution_material_requirements');

        Rls::disable('lab_executions');
        Schema::dropIfExists('lab_executions');

        Schema::table('lab_specimens', function (Blueprint $table): void {
            $table->dropUnique('lab_specimens_tenant_order_specimen_unique');
        });
        Schema::table('lab_order_items', function (Blueprint $table): void {
            $table->dropUnique('lab_order_items_tenant_order_item_unique');
        });
        Schema::table('lab_orders', function (Blueprint $table): void {
            $table->dropUnique('lab_orders_tenant_id_unique');
        });
    }
};
