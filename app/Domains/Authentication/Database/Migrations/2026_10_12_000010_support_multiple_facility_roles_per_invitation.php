<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aut_admin_bootstrap_tokens', function (Blueprint $table): void {
            $table->json('role_keys')->nullable()->after('role_key');
        });

        Schema::table('idn_admin_role_promotion_requests', function (Blueprint $table): void {
            $table->boolean('is_primary')->default(false)->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('idn_admin_role_promotion_requests', function (Blueprint $table): void {
            $table->dropColumn('is_primary');
        });

        Schema::table('aut_admin_bootstrap_tokens', function (Blueprint $table): void {
            $table->dropColumn('role_keys');
        });
    }
};
