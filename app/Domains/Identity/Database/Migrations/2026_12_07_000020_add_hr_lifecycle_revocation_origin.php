<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idn_admin_role_promotion_requests', function (Blueprint $table): void {
            $table->string('request_origin')->nullable();
            $table->uuid('hr_staff_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('idn_admin_role_promotion_requests', function (Blueprint $table): void {
            $table->dropColumn(['request_origin', 'hr_staff_id']);
        });
    }
};
