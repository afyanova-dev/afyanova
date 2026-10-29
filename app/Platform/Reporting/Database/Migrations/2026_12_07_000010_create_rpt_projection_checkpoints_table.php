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
        Schema::create('rpt_projection_checkpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('stream_name', 64);
            $table->bigInteger('checkpoint_offset')->default(0);
            $table->timestampTz('last_event_timestamp')->nullable();
            $table->string('status', 30)->default('IDLE')->index();
            $table->string('reference_config_version', 32)->default('v1.0');
            $table->timestampTz('last_rebuilt_at')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'stream_name']);
        });

        Rls::enable('rpt_projection_checkpoints');
    }

    public function down(): void
    {
        Rls::disable('rpt_projection_checkpoints');
        Schema::dropIfExists('rpt_projection_checkpoints');
    }
};
