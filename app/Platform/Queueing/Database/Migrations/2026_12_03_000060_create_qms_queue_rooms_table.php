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
        Schema::create('qms_queue_rooms', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('queue_id')->index();
            $table->uuid('room_id')->index();
            $table->timestampsTz();
            $table->unique(['queue_id', 'room_id']);
        });

        Rls::enable('qms_queue_rooms');
    }

    public function down(): void
    {
        Rls::disable('qms_queue_rooms');
        Schema::dropIfExists('qms_queue_rooms');
    }
};
