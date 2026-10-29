<?php

declare(strict_types=1);

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identity & RBAC (docs/00-foundation/03-identity-rbac.md §2, §6–§14, §17–§21,
 * §35–§39, §66, §77–§79). PHASE 3 shape.
 *
 *   User → (staff identity | non-staff) → Access Assignment {scope + role}
 *        → Permissions → Domain policy → Contextual scope → Allow/Deny
 *
 * IC §10 column shape on every tenant-scoped table. `idn_permissions` is a
 * global catalogue (no tenant_id, no RLS — a key means the same everywhere,
 * §29). The Access Assignment (Identity) is distinct from the HR Assignment
 * (docs/01-domains/25 §7.5) — HR = workforce placement, Identity = role + scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idn_permissions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->string('key')->unique();               // L1 {domain}.access | L2 {domain}.{resource}.{action}
            $table->string('layer')->default('ACTION');     // ACCESS | ACTION (§66)
            $table->string('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('idn_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->nullable()->index(); // roles are usually tenant-wide; column present per IC §10 scope shape
            $table->string('key');
            $table->string('name');
            $table->string('status')->default('ACTIVE');    // DRAFT | ACTIVE | SUSPENDED | RETIRED (§35)
            $table->uuid('parent_role_id')->nullable();     // permission inheritance only, NOT scope (§19)
            $table->string('scope_type')->default('FACILITY'); // ORG | FACILITY | DEPARTMENT | UNIT (§02 §19)
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'key']);
        });
        Rls::enable('idn_roles');

        Schema::create('idn_permission_role', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->nullable();
            $table->uuid('role_id')->index();
            $table->uuid('permission_id')->index();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['role_id', 'permission_id']);
        });
        Rls::enable('idn_permission_role');

        // tenant + optional lower scope. NULL at a level = "all at/below that level".
        Schema::create('idn_access_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->uuid('user_id')->index();
            $table->uuid('role_id')->index();
            $table->uuid('organization_id')->nullable();
            $table->uuid('department_id')->nullable();
            $table->uuid('unit_id')->nullable();
            $table->string('status')->default('ACTIVE');    // PENDING | ACTIVE | SUSPENDED | EXPIRED | REVOKED (§37)
            $table->timestampTz('effective_from')->nullable(); // temporary assignments (§38)
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->uuid('granted_by')->nullable();
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->index(['user_id', 'status']);
        });
        Rls::enable('idn_access_assignments');

        // The Identity side of "staff identity" (§4–§6). The rich Person/Staff/
        // Employment model is HR & Workforce (PHASE 11); `staff_ref` links to it.
        Schema::create('idn_staff_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->nullable();
            $table->uuid('user_id')->unique();
            $table->boolean('is_staff')->default(true);
            $table->string('staff_number')->nullable();
            $table->uuid('staff_ref')->nullable();          // HR Staff id, filled in PHASE 11
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();
        });
        Rls::enable('idn_staff_identities');

        // Separation-of-duties constraints — DATA ONLY in PHASE 3. The command
        // bus enforces dynamic SoD in PHASE 4 (§20–§21).
        Schema::create('idn_sod_constraints', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('facility_id')->nullable();
            $table->string('name');
            $table->string('kind');                         // PERMISSION_PAIR | ROLE_PAIR
            $table->string('a');
            $table->string('b');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'kind', 'a', 'b']);
        });
        Rls::enable('idn_sod_constraints');
    }

    public function down(): void
    {
        foreach (['idn_sod_constraints', 'idn_staff_identities', 'idn_access_assignments', 'idn_permission_role', 'idn_roles'] as $t) {
            Rls::disable($t);
            Schema::dropIfExists($t);
        }
        Schema::dropIfExists('idn_permissions');
    }
};
