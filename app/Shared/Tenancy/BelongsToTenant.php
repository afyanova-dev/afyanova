<?php

declare(strict_types=1);

namespace App\Shared\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Application-layer tenant scoping (IMPLEMENTATION-CONTRACTS.md §11.2,
 * docs/00-foundation/05-tenant-isolation.md §14–§16).
 *
 * A model that stores `tenant_id` uses this trait. It:
 *   - auto-fills `tenant_id` from {@see TenantContext} on create;
 *   - adds a global `where tenant_id = <context>` scope to every query.
 *
 * {@see TenantContext} is established for HTTP by {@see ResolveTenantContext}
 * and for every non-HTTP entry point (seeders, `artisan` console commands,
 * queued jobs) by {@see TenantContextScope::run} — the console-side counterpart
 * that IC §14 calls "re-establishes tenant context via Tenancy::run".
 *
 * This is the *backstop's backstop* — RLS at the database is the real control
 * (`AGENTS.md §14`). The global scope keeps developer queries honest and makes
 * "missing tenant filter" bugs impossible in the common path.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new class implements Scope
        {
            public function apply(Builder $builder, Model $model): void
            {
                $context = app(TenantContext::class);

                if (! $context->hasTenant()) {
                    // No context → match nothing (fail closed). RLS enforces the
                    // same at the DB; this keeps the app layer consistent.
                    $builder->whereRaw('1 = 0');

                    return;
                }

                $builder->where($model->getTable().'.tenant_id', $context->tenantId());
            }
        });

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $context = app(TenantContext::class);

                if ($context->hasTenant()) {
                    $model->setAttribute('tenant_id', $context->tenantId());
                }
            }
        });
    }
}
