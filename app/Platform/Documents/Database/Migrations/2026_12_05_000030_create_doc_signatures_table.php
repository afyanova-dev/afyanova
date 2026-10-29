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
        Schema::create('doc_signatures', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('document_version_id')->constrained('doc_document_versions')->cascadeOnDelete();

            $table->uuid('signer_id')->index(); // no cross-prefix FK per ADR-093
            $table->string('signer_type', 30); // STAFF, PATIENT, EXTERNAL_PARTY
            $table->string('method', 30); // DRAWN_VECTOR, CRYPTO_SEAL, OTP_ATTESTATION, PKI_CERTIFICATE
            $table->string('signature_digest', 512);
            $table->timestampTz('signed_at');

            $table->uuid('auth_event_ref')->nullable()->index(); // pointer to centralized audit, no cross-prefix FK
            $table->string('cert_fingerprint', 96)->nullable();

            $table->timestampsTz();
        });

        Rls::enable('doc_signatures');
    }

    public function down(): void
    {
        Rls::disable('doc_signatures');
        Schema::dropIfExists('doc_signatures');
    }
};
