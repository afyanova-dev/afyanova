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
        Schema::create('notif_delivery_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('notification_request_id')->index();
            $table->string('channel');
            $table->string('provider');
            $table->string('provider_message_id')->nullable();
            $table->string('masked_destination')->nullable();

            $table->string('status')->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('attempted_at');
            $table->timestampTz('delivered_at')->nullable();

            $table->timestampsTz();

            $table->foreign('notification_request_id')
                ->references('id')
                ->on('notif_requests')
                ->cascadeOnDelete();
        });

        Rls::enable('notif_delivery_attempts');
    }

    public function down(): void
    {
        Rls::disable('notif_delivery_attempts');
        Schema::dropIfExists('notif_delivery_attempts');
    }
};
