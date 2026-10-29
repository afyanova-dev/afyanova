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
            $table->string('purpose')->default('TENANT_ADMIN')->after('created_by_reference');
            $table->uuid('requested_by')->nullable()->after('purpose');
            $table->uuid('facility_id')->nullable()->after('requested_by');
            $table->text('request_reason')->nullable()->after('facility_id');
        });

        Schema::table('idn_admin_role_promotion_requests', function (Blueprint $table): void {
            $table->string('action')->default('PROMOTE')->after('scope_type');
        });
    }

    public function down(): void
    {
        Schema::table('idn_admin_role_promotion_requests', function (Blueprint $table): void {
            $table->dropColumn('action');
        });

        Schema::table('aut_admin_bootstrap_tokens', function (Blueprint $table): void {
            $table->dropColumn(['purpose', 'requested_by', 'facility_id', 'request_reason']);
        });
    }
};
