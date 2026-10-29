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
        Schema::create('idn_admin_role_promotion_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('organization_id')->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->uuid('target_user_id')->index();
            $table->uuid('role_id');
            $table->string('role_key');
            $table->string('scope_type');
            $table->string('status')->default('PENDING');
            $table->text('reason');
            $table->text('decision_reason')->nullable();
            $table->uuid('requested_by');
            $table->uuid('decided_by')->nullable();
            $table->uuid('assignment_id')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'target_user_id', 'role_id']);
        });

        Rls::enable('idn_admin_role_promotion_requests');
    }

    public function down(): void
    {
        Rls::disable('idn_admin_role_promotion_requests');
        Schema::dropIfExists('idn_admin_role_promotion_requests');
    }
};
