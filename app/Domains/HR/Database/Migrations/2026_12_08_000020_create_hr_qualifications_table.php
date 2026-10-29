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
        Schema::create('hr_qualifications', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('staff_id')->index();
            $table->string('award', 150);
            $table->string('field_of_study', 150);
            $table->string('institution', 200);
            $table->unsignedSmallInteger('completion_year')->nullable();
            $table->string('verification_status', 32)->default('UNVERIFIED');
            $table->uuid('verified_by')->nullable()->index();
            $table->timestampTz('verified_at')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('staff_id')->references('id')->on('hr_staff')->cascadeOnDelete();
        });

        Rls::enable('hr_qualifications');
    }

    public function down(): void
    {
        Rls::disable('hr_qualifications');
        Schema::dropIfExists('hr_qualifications');
    }
};
