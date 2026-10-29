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
        Schema::create('clin_procedure_protocol_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('procedure_definition_id')->index();
            $table->unsignedInteger('procedure_definition_version');
            $table->string('protocol_reference');
            $table->string('protocol_version');
            $table->uuid('approved_by_user_id');
            $table->timestampTz('approved_at');
            $table->uuid('revoked_by_user_id')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revocation_reason', 1000)->nullable();
            $table->timestampsTz();
        });

        DB::statement(
            'create unique index clin_proc_protocol_active_unique '.
            'on clin_procedure_protocol_approvals (tenant_id, facility_id, procedure_definition_id) '.
            'where revoked_at is null'
        );

        Schema::table('clin_procedures', function (Blueprint $table): void {
            $table->uuid('authorization_protocol_approval_id')->nullable();
            $table->string('authorization_protocol_version')->nullable();
        });

        Rls::enable('clin_procedure_protocol_approvals');
    }

    public function down(): void
    {
        Rls::disable('clin_procedure_protocol_approvals');
        Schema::dropIfExists('clin_procedure_protocol_approvals');
        Schema::table('clin_procedures', function (Blueprint $table): void {
            $table->dropColumn(['authorization_protocol_approval_id', 'authorization_protocol_version']);
        });
    }
};
