<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use App\Shared\Audit\AuditLogger;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONLY sanctioned privileged / cross-tenant operation path
 * (IMPLEMENTATION-CONTRACTS.md §11.4).
 *
 * Forbidden — by architecture test — inside HTTP middleware, controllers, Form
 * Requests, jobs, listeners, and anything under `Presentation/` or
 * `Application/`. It exists for schema migrations and a small number of
 * authenticated console commands only, never a web route.
 *
 * It does three things:
 *   1. writes a `shared_audit_log` entry (`action = system.rls_bypass`,
 *      `metadata.operation = $op`) BEFORE the callback runs;
 *   2. opens a transaction with `app.tenant_id` explicitly cleared, so no stale
 *      pooled-connection context can leak into the operation;
 *   3. marks the call site so the arch test can forbid it elsewhere.
 *
 * The app role (`afyanova`) is NOSUPERUSER with no `BYPASSRLS`, so this does not
 * — and cannot — read across tenants by itself. DDL in a migration is not
 * subject to row policies; any cross-tenant DML inside `$fn` still iterates
 * tenants and calls {@see Tenancy::run()} per tenant (the same rule the outbox
 * relay follows).
 */
final class SystemContext
{
    /**
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function run(SystemOperation $op, string $reason, Closure $fn): mixed
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('SystemContext::run() requires a non-empty reason.');
        }

        return DB::transaction(static function () use ($op, $reason, $fn): mixed {
            DB::statement("select set_config('app.tenant_id', '', true)");

            app(AuditLogger::class)->system('system.rls_bypass', $reason, ['operation' => $op->value]);

            return $fn();
        });
    }
}
