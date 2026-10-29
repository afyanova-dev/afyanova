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
        Schema::table('platform_super_admins', function (Blueprint $table): void {
            $table->unsignedInteger('access_version')->default(0);
        });

        Schema::create('platform_super_admin_access_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('target_user_id')->index();
            $table->uuid('requested_by')->index();
            $table->uuid('reviewed_by')->nullable()->index();
            $table->string('action'); // SUSPEND | REACTIVATE | RESET_MFA
            $table->string('status')->default('PENDING'); // PENDING | APPROVED | REJECTED
            $table->text('reason');
            $table->text('review_notes')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX platform_super_admin_access_requests_pending_unique
            ON platform_super_admin_access_requests (target_user_id, action)
            WHERE status = 'PENDING'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_super_admin_access_requests');
        Schema::table('platform_super_admins', function (Blueprint $table): void {
            $table->dropColumn('access_version');
        });
    }
};
