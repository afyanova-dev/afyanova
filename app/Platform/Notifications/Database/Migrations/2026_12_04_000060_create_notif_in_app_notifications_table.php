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
        Schema::create('notif_in_app_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            // Strictly NO cross-prefix FK to users table (F7, ADR-093, IC §10)
            $table->uuid('user_id')->index();
            $table->uuid('notification_request_id')->nullable()->index();

            $table->string('title');
            $table->text('body');
            $table->string('action_url')->nullable();
            $table->timestampTz('read_at')->nullable();

            $table->timestampsTz();
        });

        Rls::enable('notif_in_app_notifications');
    }

    public function down(): void
    {
        Rls::disable('notif_in_app_notifications');
        Schema::dropIfExists('notif_in_app_notifications');
    }
};
