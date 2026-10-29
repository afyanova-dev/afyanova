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
        Schema::create('pat_registration_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->index();
            $table->uuid('user_id')->index();
            $table->text('payload');
            $table->timestampTz('expires_at')->index();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'facility_id', 'user_id']);
        });

        Rls::enable('pat_registration_drafts');
    }

    public function down(): void
    {
        Rls::disable('pat_registration_drafts');
        Schema::dropIfExists('pat_registration_drafts');
    }
};
