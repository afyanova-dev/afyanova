<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_consumed_tokens', function (Blueprint $table): void {
            $table->uuid('tenant_id');
            $table->uuid('jti');
            $table->timestampTz('consumed_at');
            $table->timestampTz('expires_at')->index();

            $table->primary(['tenant_id', 'jti']);
        });

        Rls::enable('doc_consumed_tokens');
    }

    public function down(): void
    {
        Rls::disable('doc_consumed_tokens');
        Schema::dropIfExists('doc_consumed_tokens');
    }
};
