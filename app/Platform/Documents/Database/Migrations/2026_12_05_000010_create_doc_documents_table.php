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
        Schema::create('doc_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();

            $table->string('kind', 20); // UPLOAD, GENERATED
            $table->string('doc_type', 50)->index(); // INVOICE, RECEIPT, LAB_REPORT, etc.
            $table->string('confidentiality_level', 20)->default('STANDARD'); // STANDARD, RESTRICTED

            $table->string('source_domain', 50)->index();
            $table->string('source_entity_ref', 100)->index();
            $table->uuid('encounter_id')->nullable()->index();
            $table->uuid('patient_id')->nullable()->index();

            $table->uuid('current_version_id')->nullable();
            $table->string('status', 30)->default('AVAILABLE')->index();
            $table->uuid('superseded_by')->nullable()->index();

            $table->timestampsTz();
        });

        Rls::enable('doc_documents');
    }

    public function down(): void
    {
        Rls::disable('doc_documents');
        Schema::dropIfExists('doc_documents');
    }
};
