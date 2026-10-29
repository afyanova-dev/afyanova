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
        Schema::create('idn_emergency_access_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('assignment_id')->index();
            $table->uuid('target_user_id')->index();
            $table->uuid('requested_by');
            $table->text('reason');
            $table->string('status')->default('PENDING');
            $table->uuid('reviewed_by')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Rls::enable('idn_emergency_access_reviews');
    }

    public function down(): void
    {
        Rls::disable('idn_emergency_access_reviews');
        Schema::dropIfExists('idn_emergency_access_reviews');
    }
};
