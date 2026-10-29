<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_NOTE_INDEX = 'pat_patient_sticky_notes_one_active_per_patient';

    public function up(): void
    {
        Schema::create('pat_patient_sticky_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('patient_id')->index();
            $table->string('body', 300);
            $table->uuid('recorded_by_user_id');
            $table->uuid('updated_by_user_id')->nullable();
            $table->uuid('archived_by_user_id')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'patient_id', 'archived_at']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX '.self::ACTIVE_NOTE_INDEX.
            ' ON pat_patient_sticky_notes (tenant_id, patient_id) WHERE archived_at IS NULL'
        );

        Rls::enable('pat_patient_sticky_notes');
    }

    public function down(): void
    {
        Rls::disable('pat_patient_sticky_notes');
        DB::statement('DROP INDEX IF EXISTS '.self::ACTIVE_NOTE_INDEX);
        Schema::dropIfExists('pat_patient_sticky_notes');
    }
};
