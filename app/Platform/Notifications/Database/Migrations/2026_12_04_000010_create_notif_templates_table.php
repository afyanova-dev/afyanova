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
        Schema::create('notif_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('key');
            $table->string('name');
            $table->string('category');
            $table->jsonb('channels');
            $table->jsonb('content');
            $table->boolean('clinical_safe')->default(false);
            $table->boolean('consent_required')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();

            $table->unique(['tenant_id', 'key', 'version']);
        });

        Rls::enable('notif_templates');
    }

    public function down(): void
    {
        Rls::disable('notif_templates');
        Schema::dropIfExists('notif_templates');
    }
};
