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
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->change();
        });

        Schema::create('platform_super_admins', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('user_id')->unique();
            $table->string('status')->default('PENDING'); // PENDING | ACTIVE | SUSPENDED
            $table->string('created_by_reference', 100);
            $table->timestampTz('activated_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('platform_super_admin_bootstrap_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('user_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->string('created_by_reference', 100);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_super_admin_bootstrap_tokens');
        Schema::dropIfExists('platform_super_admins');

        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable(false)->change();
        });
    }
};
