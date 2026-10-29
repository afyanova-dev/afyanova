<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PHASE 2 — the identity spine (blueprint.md §7).
 *
 * `shared_tenants` is the platform tenant registry — NOT tenant-scoped, NO RLS
 * (login resolves the user's tenant before any tenant context exists,
 * docs/00-foundation/05-tenant-isolation.md §8, §27).
 *
 * `users` keeps the framework table name (the one documented exception,
 * IC §2) — uuid PK, `tenant_id`, account status, Fortify 2FA columns.
 * Also NOT RLS-protected: authentication reads it pre-context; user *listing*
 * isolation is an application-layer concern (docs 05 §27).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('ACTIVE'); // ACTIVE | SUSPENDED
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            // No FK — `shared_tenants` is another prefix (IC §10 / ADR-093).
            $table->uuid('tenant_id')->index();
            $table->string('name');
            // PHASE 2: one human identity = one tenant, email globally unique so
            // login needs no tenant hint. The multi-tenant identity split
            // (docs 05 §28) is PHASE 3.
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('status')->default('ACTIVE'); // ACTIVE | SUSPENDED | DEACTIVATED
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('shared_tenants');
    }
};
