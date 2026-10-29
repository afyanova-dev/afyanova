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
        Schema::create('notif_channel_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('subject_type');
            $table->uuid('subject_id')->index();

            $table->string('preferred_channel')->nullable();
            $table->jsonb('muted_categories')->default('[]');
            $table->string('quiet_hours_start')->nullable();
            $table->string('quiet_hours_end')->nullable();
            $table->string('preferred_language')->default('sw');

            $table->timestampsTz();

            $table->unique(['tenant_id', 'subject_type', 'subject_id']);
        });

        Rls::enable('notif_channel_preferences');
    }

    public function down(): void
    {
        Rls::disable('notif_channel_preferences');
        Schema::dropIfExists('notif_channel_preferences');
    }
};
