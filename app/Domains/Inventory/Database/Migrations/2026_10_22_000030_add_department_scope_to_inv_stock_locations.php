<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_stock_locations', function (Blueprint $table): void {
            $table->uuid('department_id')->nullable()->after('facility_id')->index();
        });

        DB::statement(
            'update inv_stock_locations as location '.
            'set department_id = department.id '.
            'from org_departments as department '.
            'where department.tenant_id = location.tenant_id '.
            'and department.facility_id = location.facility_id '.
            "and department.status = 'ACTIVE' ".
            "and department.code = 'PHD' ".
            "and location.code = 'PHARMACY'"
        );
    }

    public function down(): void
    {
        Schema::table('inv_stock_locations', function (Blueprint $table): void {
            $table->dropIndex(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
