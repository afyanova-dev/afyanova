<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security migration helper (IMPLEMENTATION-CONTRACTS.md §11.2,
 * docs/00-foundation/05-tenant-isolation.md §19).
 *
 * This is the ONLY sanctioned way to apply RLS — a raw `CREATE POLICY` in a
 * migration fails `afyanova:conformance`.
 *
 * The policy predicate is
 *   tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
 * The `nullif(…, '')` is required, not decorative: once `app.tenant_id` has been
 * set once on a pooled connection, after the transaction-local set expires
 * `current_setting('app.tenant_id', true)` returns the empty string (verified on
 * PostgreSQL 18.6), and `''::uuid` would raise instead of failing closed to zero
 * rows. With `nullif`: '' → NULL → `tenant_id = NULL` → zero rows, no error.
 */
final class Rls
{
    private const POLICY = 'tenant_isolation';

    private const PREDICATE = "tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid";

    /**
     * Enable + FORCE RLS on a tenant-scoped table and install the isolation
     * policy (USING for reads, WITH CHECK for writes).
     *
     * FORCE ensures the table owner is also constrained; the app role
     * (`afyanova`) is NOSUPERUSER so this actually bites.
     */
    public static function enable(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(sprintf(
            'CREATE POLICY %s ON %s USING (%s) WITH CHECK (%s)',
            self::POLICY,
            $table,
            self::PREDICATE,
            self::PREDICATE,
        ));
    }

    /**
     * Additionally forbid UPDATE and DELETE at the database — for append-only
     * tables (`shared_audit_log`, the outbox archive). Reads and inserts still
     * pass through the tenant-isolation policy.
     */
    public static function enableAppendOnly(string $table): void
    {
        // RESTRICTIVE (AND-ed with the permissive tenant policy) so `false`
        // actually blocks — a permissive `USING (false)` would just be OR-ed in
        // and ignored.
        DB::statement("CREATE POLICY {$table}_no_update ON {$table} AS RESTRICTIVE FOR UPDATE USING (false)");
        DB::statement("CREATE POLICY {$table}_no_delete ON {$table} AS RESTRICTIVE FOR DELETE USING (false)");
    }

    /**
     * The audit-log flavour (IMPLEMENTATION-CONTRACTS.md §18): tenant-isolated
     * and append-only, but ALSO able to hold platform / system rows that have no
     * tenant (`SystemContext::run` writes `tenant_id = NULL`).
     *
     * - read (USING): a tenant sees only its own rows; NULL-tenant rows are
     *   invisible to every tenant (`docs 05 §51`) and reached only via a
     *   controlled platform path.
     * - write (WITH CHECK): a tenant may insert only its own rows; a NULL-tenant
     *   row may be inserted only when there is NO tenant context (a system op) —
     *   a tenant cannot smuggle in an untenanted row.
     */
    public static function enableForAuditLog(string $table): void
    {
        $ctx = "nullif(current_setting('app.tenant_id', true), '')";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(sprintf(
            'CREATE POLICY %s ON %s USING (tenant_id = %s::uuid) WITH CHECK (tenant_id = %s::uuid OR (tenant_id IS NULL AND %s IS NULL))',
            self::POLICY,
            $table,
            $ctx,
            $ctx,
            $ctx,
        ));
        self::enableAppendOnly($table);
    }

    public static function allowPlatformAuditRead(string $table): void
    {
        DB::statement('DROP POLICY '.self::POLICY." ON {$table}");
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON shared_audit_log
            USING (
                tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                OR (
                    tenant_id IS NULL
                    AND EXISTS (
                        SELECT 1
                        FROM platform_super_admins AS platform_admin
                        JOIN users ON users.id = platform_admin.user_id
                        WHERE platform_admin.user_id = nullif(current_setting('app.platform_admin_id', true), '')::uuid
                          AND platform_admin.status = 'ACTIVE'
                          AND users.tenant_id IS NULL
                          AND users.status = 'ACTIVE'
                    )
                )
            )
            WITH CHECK (
                tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                OR (
                    tenant_id IS NULL
                    AND nullif(current_setting('app.tenant_id', true), '') IS NULL
                )
                OR (
                    tenant_id IS NULL
                    AND actor_id = nullif(current_setting('app.platform_admin_id', true), '')
                    AND EXISTS (
                        SELECT 1
                        FROM platform_super_admins AS platform_admin
                        JOIN users ON users.id = platform_admin.user_id
                        WHERE platform_admin.user_id = nullif(current_setting('app.platform_admin_id', true), '')::uuid
                          AND platform_admin.status = 'ACTIVE'
                          AND users.tenant_id IS NULL
                          AND users.status = 'ACTIVE'
                    )
                )
            )
        SQL);
    }

    public static function restoreTenantOnlyAuditRead(string $table): void
    {
        DB::statement('DROP POLICY '.self::POLICY." ON {$table}");
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON shared_audit_log
            USING (
                tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
            )
            WITH CHECK (
                tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
                OR (
                    tenant_id IS NULL
                    AND nullif(current_setting('app.tenant_id', true), '') IS NULL
                )
            )
        SQL);
    }

    /** Drop everything `enable()` / `enableAppendOnly()` added — for migration rollback. */
    public static function disable(string $table): void
    {
        DB::statement("DROP POLICY IF EXISTS {$table}_no_update ON {$table}");
        DB::statement("DROP POLICY IF EXISTS {$table}_no_delete ON {$table}");
        DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }
}
