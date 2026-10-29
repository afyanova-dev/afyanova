<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.2) — staff user links model
 * (25-hr-and-workforce-domain.md §2, §6, §7; Invariant 7.3).
 *
 * Optional 1:1 mapping between Staff and Identity.User.
 * Unlinking never deletes either Staff or User.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_staff_user_links', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('staff_id')->index();
            $table->uuid('user_id')->index();
            $table->timestampTz('linked_at');
            $table->uuid('linked_by');

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'staff_id']);
            $table->unique(['tenant_id', 'user_id']);
            $table->foreign('staff_id')->references('id')->on('hr_staff')->cascadeOnDelete();
        });

        Rls::enable('hr_staff_user_links');
    }

    public function down(): void
    {
        Rls::disable('hr_staff_user_links');
        Schema::dropIfExists('hr_staff_user_links');
    }
};
