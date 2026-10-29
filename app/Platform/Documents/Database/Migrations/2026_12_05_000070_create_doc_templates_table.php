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
        Schema::create('doc_templates', function (Blueprint $table): void {
            $table->string('id', 100);
            $table->uuid('tenant_id')->index();

            $table->string('doc_type', 50)->index();
            $table->string('name', 255);
            $table->string('engine', 50)->default('blade');
            $table->text('layout');
            $table->jsonb('variables_schema')->nullable();
            $table->string('version', 20)->default('1.0');
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->primary(['tenant_id', 'id', 'version']);
        });

        Rls::enable('doc_templates');
    }

    public function down(): void
    {
        Rls::disable('doc_templates');
        Schema::dropIfExists('doc_templates');
    }
};
