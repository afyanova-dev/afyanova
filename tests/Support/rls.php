<?php

declare(strict_types=1);
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 |----------------------------------------------------------------------
 | RLS test helper  (IMPLEMENTATION-CONTRACTS.md §17.2, Appendix A)
 |----------------------------------------------------------------------
 | Proves tenant isolation at the DATABASE layer, independent of any
 | Eloquent global scope: a second raw connection with the wrong / no
 | `app.tenant_id` GUC must see zero rows and never error.
 |
 | Requires a `pgsql_raw` connection (config/database.php) that carries
 | NO model scopes and does NOT run Tenancy::run.
 */

if (! function_exists('assertRlsIsolates')) {
    /**
     * @param  string  $table  fully-qualified table name (e.g. bill_invoices)
     * @param  string  $tenantA  uuid of the tenant that owns the seeded row
     * @param  string  $rowIdOfA  uuid primary key of a row belonging to tenant A
     * @param  string  $tenantB  uuid of a different tenant
     * @param  array<string, mixed>  $rowTemplate  non-null columns a full insert
     *                                             on `$table` needs, minus `id` / `tenant_id` (for the cross-tenant
     *                                             write check — so WITH CHECK, not a NOT NULL constraint, is what
     *                                             rejects it)
     */
    function assertRlsIsolates(string $table, string $tenantA, string $rowIdOfA, string $tenantB, array $rowTemplate = []): void
    {
        $raw = DB::connection('pgsql_raw');

        // wrong tenant → 0 rows
        $raw->transaction(function () use ($raw, $table, $rowIdOfA, $tenantB): void {
            $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantB]);
            expect($raw->table($table)->where('id', $rowIdOfA)->count())->toBe(0);
        });

        // no context, then empty-string context → 0 rows, no error (nullif normalises both)
        $raw->transaction(function () use ($raw, $table, $rowIdOfA): void {
            expect($raw->table($table)->where('id', $rowIdOfA)->count())->toBe(0);
            $raw->statement("select set_config('app.tenant_id', '', true)");
            expect($raw->table($table)->where('id', $rowIdOfA)->count())->toBe(0);
        });

        // cross-tenant write → rejected by WITH CHECK
        expect(fn () => $raw->transaction(function () use ($raw, $table, $tenantB, $tenantA, $rowTemplate): void {
            $raw->statement("select set_config('app.tenant_id', ?, true)", [$tenantB]);
            $raw->table($table)->insert([
                ...$rowTemplate,
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantA, // mismatched on purpose
            ]);
        }))->toThrow(QueryException::class);
    }
}
