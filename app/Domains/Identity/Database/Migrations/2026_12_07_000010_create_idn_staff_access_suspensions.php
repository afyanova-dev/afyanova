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
        Schema::create('idn_staff_access_suspensions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('staff_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('actor_id');
            $table->string('status')->default('ACTIVE');
            $table->text('reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'staff_id', 'status']);
        });
        Rls::enable('idn_staff_access_suspensions');

        Schema::create('idn_staff_access_suspension_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('suspension_id');
            $table->uuid('assignment_id');
            $table->unsignedBigInteger('assignment_version');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'suspension_id', 'assignment_id'], 'idn_staff_suspend_assignment_unique');
            $table->foreign('suspension_id')
                ->references('id')
                ->on('idn_staff_access_suspensions')
                ->cascadeOnDelete();
        });
        Rls::enable('idn_staff_access_suspension_assignments');
    }

    public function down(): void
    {
        Rls::disable('idn_staff_access_suspension_assignments');
        Schema::dropIfExists('idn_staff_access_suspension_assignments');
        Rls::disable('idn_staff_access_suspensions');
        Schema::dropIfExists('idn_staff_access_suspensions');
    }
};
