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
            $table->string('role_key')->nullable()->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('aut_admin_bootstrap_tokens', function (Blueprint $table): void {
            $table->dropColumn('role_key');
        });
    }
};
