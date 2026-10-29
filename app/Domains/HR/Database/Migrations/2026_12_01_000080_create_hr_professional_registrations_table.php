<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.2) — professional registrations aggregate root
 * (25-hr-and-workforce-domain.md §3, §6, §7, §8; Invariant 7.8, 7.14).
 *
 * One-to-many per staff. Status is a reflection/cache of external licensing authority.
 * Unique per tenant + council + licence_number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_professional_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->uuid('staff_id')->index();
            $table->string('council', 100);
            $table->string('category', 100);
            $table->string('licence_number', 100);
            $table->date('issued_on');
            $table->date('expires_on');
            $table->timestampTz('verified_at')->nullable();
            $table->string('verification_source', 32);
            $table->string('status', 32);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->foreign('staff_id')->references('id')->on('hr_staff')->cascadeOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX hr_registrations_licence_unique
             ON hr_professional_registrations (tenant_id, council, licence_number)'
        );

        Rls::enable('hr_professional_registrations');
    }

    public function down(): void
    {
        Rls::disable('hr_professional_registrations');
        DB::statement('DROP INDEX IF EXISTS hr_registrations_licence_unique');
        Schema::dropIfExists('hr_professional_registrations');
    }
};
