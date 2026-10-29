<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The one place `app.tenant_id` is ever written
 * (IMPLEMENTATION-CONTRACTS.md §11.3, docs/00-foundation/05-tenant-isolation.md
 * §20, §92).
 *
 * Every DB-touching unit of work for a tenant runs inside `Tenancy::run()`:
 *   - opens a transaction (or joins the outer one via a savepoint if already
 *     inside a transaction),
 *   - issues `select set_config('app.tenant_id', ?, true)` as the first
 *     statement — `true` = transaction-local, bound parameter, never
 *     string-interpolated,
 *   - runs the callback,
 *   - commits.
 *
 * Because the GUC is transaction-local it is cleared automatically on
 * commit/rollback, so a pooled connection returned to the pool holds no
 * residual tenant context. Compatible with PgBouncer transaction mode.
 */
final class Tenancy
{
    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function run(string $tenantId, Closure $fn): mixed
    {
        return DB::transaction(static function () use ($tenantId, $fn): mixed {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);

            return $fn();
        });
    }
}
