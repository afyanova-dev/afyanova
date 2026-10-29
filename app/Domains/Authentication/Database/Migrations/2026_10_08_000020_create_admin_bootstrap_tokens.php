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
        // Public setup resolves only an opaque token hash before establishing a
        // tenant context; every mutation is re-read and performed under RLS.
        Schema::create('aut_admin_bootstrap_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
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
        Schema::dropIfExists('aut_admin_bootstrap_tokens');
    }
};
