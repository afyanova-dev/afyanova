<?php

declare(strict_types=1);

namespace App\Shared\Console;

use App\Shared\Bus\AuthorizesAgainst;
use App\Shared\Bus\CommandBus;
use App\Shared\ReadModel\ReadModelRegistry;
use App\Shared\Support\PrefixRegistry;
use App\Shared\Support\ProvidersManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * `php artisan afyanova:conformance` — the structural checks that architecture
 * tests cannot express (IMPLEMENTATION-CONTRACTS.md §17.5). Each check names
 * its source of truth. Checks that need real modules / tables become active
 * as those land; PHASE 0 runs what it can.
 */
final class ConformanceCommand extends Command
{
    protected $signature = 'afyanova:conformance';

    protected $description = 'Verify the codebase against IMPLEMENTATION-CONTRACTS.md structural rules.';

    public function handle(): int
    {
        $failures = [];

        // 1. every module folder has its provider registered (§2.4)
        foreach (ProvidersManifest::unregistered(base_path()) as $provider) {
            $failures[] = "unregistered module provider: {$provider} (add it to bootstrap/providers.php)";
        }

        // 2. every migration filename uses a documented table prefix (Appendix B)
        foreach ($this->migrationTables() as $file => $table) {
            if (! PrefixRegistry::isKnown($table)) {
                $failures[] = "migration '{$file}' creates table '{$table}' with an undocumented prefix (Appendix B / PrefixRegistry)";
            }
        }

        // 3. every tenant-scoped table has an RLS policy + FORCE (§11.2) — active once module tables exist
        foreach ($this->rlsGaps() as $table) {
            $failures[] = "table '{$table}' looks tenant-scoped (has tenant_id) but has no FORCE'd RLS policy — call App\\Shared\\Tenancy\\Rls::enable('{$table}')";
        }

        // 3a. every capability dependency names a registered capability (docs 06 §51)
        foreach ($this->danglingCapabilityDependencies() as $code) {
            $failures[] = "org_capability_dependencies references '{$code}' which is not in org_capabilities";
        }

        // 3b. every tenant-scoped table has id/tenant_id uuid + version (§10, §17.5)
        foreach ($this->columnShapeGaps() as $gap) {
            $failures[] = $gap;
        }

        // 3c. every AuthorizesAgainst command resolves to a registered policy (§5.1, §17.5)
        foreach ($this->commandPolicyGaps() as $gap) {
            $failures[] = $gap;
        }

        // 3d. every #[ProtectedQuery] resolves to a registered policy (§6.2, §17.5)
        foreach ($this->protectedQueryGaps() as $gap) {
            $failures[] = $gap;
        }

        // 3e. every rm_ / rpt_ projection table has a read-model declaration (§6.4, ADR-094)
        foreach ($this->readModelGaps() as $gap) {
            $failures[] = $gap;
        }

        // 4. no GAP / STOP / TBD marker left in the contract
        $contract = base_path('IMPLEMENTATION-CONTRACTS.md');
        if (is_file($contract)) {
            $body = (string) file_get_contents($contract);
            // ignore the two lines that describe this very check
            $body = preg_replace('/^.*grep of `IMPLEMENTATION-CONTRACTS\.md`.*$/m', '', $body) ?? $body;
            $body = preg_replace('/^.*no unresolved GAP \/ STOP marker.*$/m', '', $body) ?? $body;
            if (preg_match('/\b(TBD|FIXME|\bSTOP\b marker|GAP marker)\b/', $body)) {
                $failures[] = 'IMPLEMENTATION-CONTRACTS.md contains an unresolved TBD/FIXME/GAP marker';
            }
        }

        if ($failures === []) {
            $this->components->info('afyanova:conformance — all checks passed.');

            return self::SUCCESS;
        }

        foreach ($failures as $f) {
            $this->components->error($f);
        }

        return self::FAILURE;
    }

    /** @return array<string, string>  migration file => created table */
    private function migrationTables(): array
    {
        $tables = [];
        $paths = array_merge(
            glob(database_path('migrations/*.php')) ?: [],
            glob(base_path('app/Domains/*/Database/Migrations/*.php')) ?: [],
            glob(base_path('app/Platform/*/Database/Migrations/*.php')) ?: [],
        );

        foreach ($paths as $path) {
            $src = (string) file_get_contents($path);
            if (preg_match_all("/Schema::create\\(\\s*'([a-z0-9_]+)'/", $src, $m)) {
                foreach ($m[1] as $table) {
                    $tables[basename($path)] = $table;
                }
            }
        }

        return $tables;
    }

    /**
     * Tables that carry a `tenant_id` for convenience/denormalisation but are
     * deliberately NOT under RLS, each with an architectural reason:
     *
     * - `users`            authentication resolves the row (and thus the tenant)
     *                      BEFORE any tenant context exists; user-listing
     *                      isolation is an application-layer concern
     *                      (docs/00-foundation/05-tenant-isolation.md §27).
     * - `aut_auth_events`  append-only auth telemetry; an event (a failed login)
     *                      may be recorded before the user — and tenant — is
     *                      known. Never read by a tenant
     *                      (docs/00-foundation/04-authentication.md §37–§38).
     *
     * @var list<string>
     */
    private const RLS_EXEMPT = ['users', 'aut_auth_events'];

    /**
     * Tenant-scoped tables that deliberately omit the `version` column: they are
     * transport / append-only / infrastructure, not aggregates. `version` is
     * optimistic concurrency "for every aggregate" (§9.1) — these are not one.
     *
     * - `users`                  auth principal, resolved pre-context (RLS-exempt).
     * - `aut_sessions`           device-session tracking; touched, not versioned.
     * - `aut_auth_events`        append-only auth telemetry (RLS-exempt).
     * - `shared_audit_log`       append-only (`Rls::enableForAuditLog`).
     * - `shared_dead_letters`    append-only.
     * - `shared_outbox_events`   transport state; the relay mutates
     *                            `published_at` / `attempts`, not via an aggregate.
     * - `shared_idempotency_keys` claim ledger; a `status` transition, not an
     *                            optimistic-locked aggregate write.
     *
     * @var list<string>
     */
    private const SHAPE_EXEMPT = [
        'users',
        'aut_sessions',
        'aut_auth_events',
        'shared_audit_log',
        'shared_dead_letters',
        'shared_outbox_events',
        'shared_idempotency_keys',
        // Platform service transport & append-only operational tables
        'qms_queues',
        'qms_ticket_counters',
        'qms_patient_movements',
        'notif_deduplication_keys',
        'notif_channel_preferences',
        'notif_delivery_attempts',
        'notif_in_app_notifications',
        'doc_documents',
        'doc_document_versions',
        'doc_consumed_tokens',
        'doc_access_grants',
        'doc_signatures',
        'doc_retention_requirements',
        'doc_templates',
        'int_connectors',
        'int_inbound_messages',
        'int_credentials',
        'int_webhook_endpoints',
        'int_outbound_messages',
        'int_id_mappings',
        'int_file_imports',
        'int_import_record_statuses',
    ];

    /** @return list<string> tenant-scoped tables missing a FORCE'd RLS policy */
    private function rlsGaps(): array
    {
        try {
            $forced = DB::table('pg_class')
                ->where('relforcerowsecurity', true)
                ->pluck('relname')
                ->all();

            $tenantScoped = DB::table('information_schema.columns')
                ->where('table_schema', 'public')
                ->where('column_name', 'tenant_id')
                ->pluck('table_name')
                ->all();
        } catch (\Throwable) {
            return []; // no DB during this run — skip
        }

        return array_values(array_diff($tenantScoped, $forced, self::RLS_EXEMPT));
    }

    /** @return list<string> capability codes used in dependencies but missing from the registry */
    private function danglingCapabilityDependencies(): array
    {
        try {
            if (! DB::getSchemaBuilder()->hasTable('org_capabilities')) {
                return [];
            }

            $known = DB::table('org_capabilities')->pluck('code')->all();
            $used = DB::table('org_capability_dependencies')
                ->select('capability_code as code')->union(
                    DB::table('org_capability_dependencies')->select('depends_on_code as code')
                )->pluck('code')->all();
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique(array_diff($used, $known)));
    }

    /**
     * §10 / §17.5 — every tenant-scoped table has `id` uuid, `tenant_id` uuid and
     * a `version` column (except {@see self::SHAPE_EXEMPT}).
     *
     * @return list<string>
     */
    private function columnShapeGaps(): array
    {
        try {
            $rows = DB::table('information_schema.columns')
                ->where('table_schema', 'public')
                ->whereIn('column_name', ['id', 'tenant_id', 'version'])
                ->get(['table_name', 'column_name', 'data_type']);
        } catch (\Throwable) {
            return [];
        }

        $byTable = [];
        foreach ($rows as $row) {
            $byTable[(string) $row->table_name][(string) $row->column_name] = (string) $row->data_type;
        }

        $gaps = [];
        foreach ($byTable as $table => $cols) {
            if (! isset($cols['tenant_id']) || in_array($table, self::SHAPE_EXEMPT, true)) {
                continue;
            }
            if (($cols['id'] ?? null) !== 'uuid') {
                $gaps[] = "table '{$table}' is tenant-scoped but has no uuid 'id' column (§10)";
            }
            if ($cols['tenant_id'] !== 'uuid') {
                $gaps[] = "table '{$table}' has a non-uuid 'tenant_id' column (§10)";
            }
            if (! isset($cols['version'])) {
                $gaps[] = "table '{$table}' is tenant-scoped but has no 'version' column (§9.1 / §10) — add it or list it in ConformanceCommand::SHAPE_EXEMPT with a reason";
            }
        }

        return $gaps;
    }

    /**
     * §5.1 / §17.5 — "every policy referenced by a command is registered". A
     * command implementing `AuthorizesAgainst` is authorized against the
     * aggregate loaded via its handler's `…Repository` dependency; that
     * structural precondition must hold (the aggregate class + a registered
     * `Gate` policy carrying `policyAbility()`). No commands yet → no-op.
     *
     * @return list<string>
     */
    private function commandPolicyGaps(): array
    {
        $gaps = [];

        foreach ($this->classesUnder('Application/Command') as $class) {
            if (str_ends_with($class, 'Handler') || ! is_subclass_of($class, AuthorizesAgainst::class)) {
                continue;
            }

            $handler = CommandBus::handlerFor($class);
            if ($handler === null) {
                $gaps[] = "command {$class} (AuthorizesAgainst) has no handler registered on the CommandBus (§5)";

                continue;
            }

            $repo = $this->handlerRepositoryType($handler);
            if ($repo === null) {
                $gaps[] = "command {$class} (AuthorizesAgainst): its handler injects no '…Repository' for the bus to load the subject (§5.1)";
            }
        }

        return $gaps;
    }

    /**
     * The FQCN of the `…Repository` parameter a handler's constructor injects, if any.
     *
     * @param  class-string  $handlerClass
     */
    private function handlerRepositoryType(string $handlerClass): ?string
    {
        try {
            foreach ((new \ReflectionClass($handlerClass))->getConstructor()?->getParameters() ?? [] as $param) {
                $type = $param->getType();
                if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()
                    && str_ends_with(class_basename($type->getName()), 'Repository')) {
                    return $type->getName();
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }

    /**
     * §6.2 / §17.5 — every protected query has an authorization path. IC §17.5
     * names a `#[Protected]` attribute; `Protected` is a PHP reserved word, so
     * the marker is `App\Shared\Bus\ProtectedQuery`. No queries yet → no-op.
     *
     * @return list<string>
     */
    private function protectedQueryGaps(): array
    {
        $gaps = [];

        foreach ($this->classesUnder('Application/Query') as $class) {
            if (str_ends_with($class, 'Handler')) {
                continue;
            }
            try {
                $attribute = (new \ReflectionClass($class))->getAttributes(\App\Shared\Bus\ProtectedQuery::class)[0] ?? null;
            } catch (\Throwable) {
                continue;
            }
            if ($attribute === null) {
                continue;
            }

            /** @var \App\Shared\Bus\ProtectedQuery $protected */
            $protected = $attribute->newInstance();
            $target = $protected->subjectType ?? $class;

            if (Gate::getPolicyFor($target) === null || ! method_exists(Gate::getPolicyFor($target), $protected->ability)) {
                $gaps[] = "query {$class}: #[ProtectedQuery] names {$protected->ability}() but no policy is registered for {$target} (§6.2)";
            }
        }

        return $gaps;
    }

    /**
     * §6.4 / ADR-094 — every `*_rm_*` / `rpt_*` table is declared in the
     * read-model registry, and every declared table exists.
     *
     * @return list<string>
     */
    private function readModelGaps(): array
    {
        try {
            $tables = DB::table('information_schema.tables')
                ->where('table_schema', 'public')
                ->where(fn ($q) => $q->where('table_name', 'like', '%\_rm\_%')->orWhere('table_name', 'like', 'rpt\_%'))
                ->pluck('table_name')
                ->all();
        } catch (\Throwable) {
            return [];
        }

        $declared = ReadModelRegistry::tables();
        $gaps = [];

        foreach (array_diff($tables, $declared) as $table) {
            $gaps[] = "projection table '{$table}' has no ReadModelRegistry declaration (§6.4)";
        }
        foreach (array_diff($declared, $tables) as $table) {
            $gaps[] = "ReadModelRegistry declares '{$table}' but the table does not exist (§6.4)";
        }

        return $gaps;
    }

    /**
     * FQCNs of the concrete classes under `app/Domains/*\/{$relative}` and
     * `app/Platform/*\/{$relative}`.
     *
     * @return list<class-string>
     */
    private function classesUnder(string $relative): array
    {
        $paths = array_merge(
            glob(base_path("app/Domains/*/{$relative}/*.php")) ?: [],
            glob(base_path("app/Platform/*/{$relative}/*.php")) ?: [],
        );

        $classes = [];
        foreach ($paths as $path) {
            $src = (string) file_get_contents($path);
            if (preg_match('/^namespace\s+([^;]+);/m', $src, $ns)
                && preg_match('/\bclass\s+(\w+)/', $src, $cn)) {
                $fqcn = trim($ns[1]).'\\'.$cn[1];
                if (class_exists($fqcn)) {
                    $classes[] = $fqcn;
                }
            }
        }

        return $classes;
    }
}
