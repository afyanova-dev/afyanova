<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived, per-user documentation drafts for the clinician workstation.
 *
 * These are NOT clinical records: nothing here is signed, audited, or visible
 * to another user. The payload is encrypted at rest and expires after seven
 * days. A draft is keyed by the author, encounter, and section so one
 * clinician's unsaved note never appears in another clinician's workspace, even
 * when they share a device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clin_documentation_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('patient_id');
            $table->uuid('encounter_id');
            $table->string('section', 20);
            $table->text('payload');
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'user_id', 'encounter_id', 'section']);
            $table->index(['tenant_id', 'encounter_id']);
        });

        Rls::enable('clin_documentation_drafts');
    }

    public function down(): void
    {
        Rls::disable('clin_documentation_drafts');
        Schema::dropIfExists('clin_documentation_drafts');
    }
};
