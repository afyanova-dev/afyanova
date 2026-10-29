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
        Schema::create('notif_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('template_key');
            $table->unsignedInteger('template_version')->default(1);
            $table->string('recipient_type');
            $table->uuid('recipient_id')->index();

            $table->string('on_behalf_of_domain');
            $table->string('on_behalf_of_entity');
            $table->string('consent_basis_ref')->nullable();
            $table->string('priority');
            $table->string('dedupe_key')->nullable()->index();

            $table->string('status')->index();
            $table->string('suppression_reason')->nullable();
            $table->jsonb('context_payload')->default('{}');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });

        Rls::enable('notif_requests');
    }

    public function down(): void
    {
        Rls::disable('notif_requests');
        Schema::dropIfExists('notif_requests');
    }
};
