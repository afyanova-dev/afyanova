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
        Schema::create('int_import_record_statuses', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->foreignUuid('file_import_id')->constrained('int_file_imports')->cascadeOnDelete();

            $table->integer('record_index');
            $table->string('record_ref', 150)->nullable()->index();
            $table->string('translated_to', 100)->nullable();
            $table->string('dispatch_status', 30)->default('PENDING')->index();
            $table->string('domain_outcome', 30)->default('PENDING')->index();
            $table->text('outcome_reason')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'file_import_id', 'record_index'], 'int_import_record_status_unique');
        });

        Rls::enable('int_import_record_statuses');
    }

    public function down(): void
    {
        Rls::disable('int_import_record_statuses');
        Schema::dropIfExists('int_import_record_statuses');
    }
};
