<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'idn_emergency_access_reviews',
            'idn_staff_access_suspensions',
            'idn_admin_role_promotion_requests',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedInteger('version')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'idn_admin_role_promotion_requests',
            'idn_staff_access_suspensions',
            'idn_emergency_access_reviews',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('version');
            });
        }
    }
};
