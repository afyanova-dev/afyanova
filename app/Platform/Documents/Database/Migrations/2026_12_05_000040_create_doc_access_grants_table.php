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
        Schema::create('doc_access_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('document_id')->constrained('doc_documents')->cascadeOnDelete();

            $table->uuid('principal_id')->index(); // no cross-prefix FK per ADR-093
            $table->string('scope', 50)->default('VIEW'); // VIEW, DOWNLOAD
            $table->timestampTz('expires_at');
            $table->uuid('granted_by')->index(); // no cross-prefix FK per ADR-093
            $table->timestampTz('granted_at');

            $table->timestampsTz();

            $table->index(['tenant_id', 'document_id', 'principal_id', 'expires_at']);
        });

        Rls::enable('doc_access_grants');
    }

    public function down(): void
    {
        Rls::disable('doc_access_grants');
        Schema::dropIfExists('doc_access_grants');
    }
};
