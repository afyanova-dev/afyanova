<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 11 (increment HR.1) — human employee identity
 * (25-hr-and-workforce-domain.md §2, §6; ADR-206).
 *
 * Distinct from Staff: Person represents the biological human identity, demographics,
 * contact details, and next of kin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_persons', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();

            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->date('date_of_birth');
            $table->string('gender', 20);

            $table->string('national_id', 50)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('physical_address')->nullable();

            $table->string('next_of_kin_name', 150)->nullable();
            $table->string('next_of_kin_relationship', 50)->nullable();
            $table->string('next_of_kin_phone', 50)->nullable();

            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });

        DB::statement(
            'CREATE UNIQUE INDEX hr_persons_tenant_national_id
             ON hr_persons (tenant_id, national_id)
             WHERE national_id IS NOT NULL'
        );

        Rls::enable('hr_persons');
    }

    public function down(): void
    {
        Rls::disable('hr_persons');
        DB::statement('DROP INDEX IF EXISTS hr_persons_tenant_national_id');
        Schema::dropIfExists('hr_persons');
    }
};
