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
        Schema::create('doc_document_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('document_id')->constrained('doc_documents')->cascadeOnDelete();

            $table->unsignedInteger('version_no');
            $table->string('storage_key', 512)->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('byte_size');
            $table->char('content_hash', 64)->index();

            // Generated-specific fields
            $table->string('template_id', 100)->nullable();
            $table->string('template_version', 50)->nullable();
            $table->jsonb('generation_payload')->nullable();
            $table->char('generation_context_hash', 64)->nullable();

            // Redaction-specific fields
            $table->uuid('derived_from_version_id')->nullable()->index();
            $table->text('redaction_reason')->nullable();
            $table->uuid('redacted_by')->nullable();

            $table->string('status', 30)->default('PENDING_SCAN')->index();
            $table->uuid('created_by')->index(); // actor ID, no cross-prefix FK per ADR-093
            $table->timestampTz('disposed_at')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'document_id', 'version_no']);
        });

        Rls::enable('doc_document_versions');
    }

    public function down(): void
    {
        Rls::disable('doc_document_versions');
        Schema::dropIfExists('doc_document_versions');
    }
};
