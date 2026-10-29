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
        Schema::create('doc_retention_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('document_id')->constrained('doc_documents')->cascadeOnDelete();

            $table->string('imposed_by', 30); // SOURCE_DOMAIN, GOVERNANCE, LEGAL_HOLD
            $table->timestampTz('retain_until')->nullable();
            $table->boolean('indefinite')->default(false);
            $table->text('reason');
            $table->uuid('actor_id')->index(); // no cross-prefix FK per ADR-093
            $table->timestampTz('released_at')->nullable();

            $table->timestampsTz();
        });

        Rls::enable('doc_retention_requirements');
    }

    public function down(): void
    {
        Rls::disable('doc_retention_requirements');
        Schema::dropIfExists('doc_retention_requirements');
    }
};
