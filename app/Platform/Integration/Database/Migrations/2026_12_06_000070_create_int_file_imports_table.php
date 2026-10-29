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
        Schema::create('int_file_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('connector_id')->index();

            $table->string('external_ref', 150)->nullable()->index();
            $table->string('filename', 255);
            $table->bigInteger('byte_size');
            $table->integer('record_count')->default(0);
            $table->integer('processed_count')->default(0);
            $table->integer('committed_count')->default(0);
            $table->integer('rejected_count')->default(0);
            $table->string('status', 30)->default('RECEIVED')->index();
            $table->text('failure_reason')->nullable();

            $table->timestampsTz();
        });

        Rls::enable('int_file_imports');
    }

    public function down(): void
    {
        Rls::disable('int_file_imports');
        Schema::dropIfExists('int_file_imports');
    }
};
