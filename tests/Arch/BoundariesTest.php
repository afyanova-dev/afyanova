<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture rules  (IMPLEMENTATION-CONTRACTS.md §17.3)
|--------------------------------------------------------------------------
| These are the rules as intent. PHASE 0 wires the harness and every rule
| that can run on the current tree. The module-boundary rules activate
| automatically as soon as the first domain lands (PHASE 5), and each gets
| a violating fixture then — "a rule that cannot be made to fail on a real
| violation is not a rule" (§17.3).
|
| `Tests\Arch\Fixtures\*` holds deliberate counter-examples; it is
| `->ignoring()`-excluded from the rules it breaks on purpose.
*/

/**
 * Recursive glob — a tiny helper for the content-scan rules below.
 *
 * @return list<string>
 */
function rglob(string $dir, string $pattern): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

$appDir = dirname(__DIR__, 2).'/app';
$hasDomains = glob($appDir.'/Domains/*', GLOB_ONLYDIR) !== [];
$hasPlatform = glob($appDir.'/Platform/*', GLOB_ONLYDIR) !== [];
$hasContractEvents = glob($appDir.'/Domains/*/Contracts/Event', GLOB_ONLYDIR) !== [];

// ── Always-on rules ────────────────────────────────────────────────────

arch('strict types everywhere in app/')
    ->expect('App')
    ->toUseStrictTypes()
    ->group('arch');

arch('no debug leftovers')
    ->expect('App')
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->group('arch');

arch('no facility-type branching (ADR-005, AGENTS §15)')
    ->expect('App')
    ->not->toUse(['facility_type'])
    ->group('arch');

arch('php preset')
    ->preset()->php()
    ->ignoring(['App\Shared\Support', 'Tests\Arch\Fixtures'])
    ->group('arch');

arch('security preset')
    ->preset()->security()
    ->ignoring([
        'Tests\Arch\Fixtures',
        // IC §5 step 7 mandates a "serialized result" for the idempotency row;
        // IdempotencyStore round-trips its OWN first-party data (its complete()
        // wrote it) from the RLS-scoped shared_idempotency_keys table.
        'App\Shared\Idempotency\IdempotencyStore',
    ])
    ->group('arch');

arch('shared kernel depends on no domain / platform module (IC §2.2)')
    ->expect('App\Shared')
    ->not->toUse(['App\Domains', 'App\Platform'])
    ->group('arch');

// ── Tenancy invariants (IC §11.3 / §11.4) ─────────────────────────────

test('Tenancy::run is the only writer of app.tenant_id', function () use ($appDir): void {
    $offenders = [];
    foreach (rglob($appDir, '*.php') as $file) {
        if (str_ends_with($file, 'Shared/Tenancy/Tenancy.php') || str_ends_with($file, 'Shared/Tenancy/SystemContext.php')) {
            continue;
        }
        if (str_contains((string) file_get_contents($file), "set_config('app.tenant_id'")) {
            $offenders[] = str_replace($appDir.'/', '', $file);
        }
    }
    expect($offenders)->toBe([]);
})->group('arch');

test('no foreign key crosses a table-prefix boundary (IC §10, ADR-093)', function () use ($appDir): void {
    $prefix = static fn (string $table): string => explode('_', $table)[0].'_';
    $migrations = array_merge(
        glob(dirname($appDir).'/database/migrations/*.php') ?: [],
        rglob($appDir.'/Domains', '*.php'),
        rglob($appDir.'/Platform', '*.php'),
    );
    $migrations = array_values(array_filter($migrations, static fn (string $f): bool => str_contains($f, '/migrations/') || str_contains($f, '/Migrations/')));

    $offenders = [];
    foreach ($migrations as $file) {
        $src = (string) file_get_contents($file);
        if (! preg_match_all("/Schema::(?:create|table)\\(\\s*'([a-z0-9_]+)'/", $src, $owns)) {
            continue;
        }
        $ownPrefixes = array_unique(array_map($prefix, $owns[1]));

        // explicit references: ->constrained('x'), ->on('x'), ->references(...)->on('x')
        preg_match_all("/->(?:constrained|on)\\(\\s*'([a-z0-9_]+)'/", $src, $refs);
        foreach ($refs[1] as $target) {
            if (! in_array($prefix($target), $ownPrefixes, true)) {
                $offenders[] = str_replace(dirname($appDir).'/', '', $file)." → FK to '{$target}'";
            }
        }
        // bare ->constrained() infers the table — forbid it in a cross-prefix-risky
        // world; name the table so this rule can verify it.
        if (preg_match('/->constrained\(\s*\)/', $src)) {
            $offenders[] = str_replace(dirname($appDir).'/', '', $file).' → bare ->constrained() (name the referenced table)';
        }
    }
    expect($offenders)->toBe([]);
})->group('arch');

test('no ad-hoc RLS escapes in app code (IC §11.4)', function () use ($appDir): void {
    $offenders = [];
    foreach (rglob($appDir, '*.php') as $file) {
        // Shared/Tenancy is the sanctioned home of every RLS primitive.
        if (str_contains($file, '/Shared/Tenancy/')) {
            continue;
        }
        $src = (string) file_get_contents($file);
        foreach (['withoutGlobalScopes(', 'DB::unprepared(', 'SET ROLE '] as $needle) {
            if (str_contains($src, $needle)) {
                $offenders[] = str_replace($appDir.'/', '', $file).' :: '.$needle;
            }
        }
    }
    expect($offenders)->toBe([]);
})->group('arch');

// IC §7.2 — "no `DB::table('shared_outbox_events')` in a repository. OutboxWriter
// owns the schema." Aggregate repositories call OutboxWriter::write(); the relay
// reads the table. Nothing else queries it.
test('the shared_outbox_events table is queried only by OutboxWriter / OutboxRelay (IC §7.2)', function () use ($appDir): void {
    $allowed = ['Shared/Events/OutboxWriter.php', 'Shared/Events/OutboxRelay.php'];
    $offenders = [];
    foreach (rglob($appDir, '*.php') as $file) {
        $rel = str_replace($appDir.'/', '', $file);
        if (in_array($rel, $allowed, true)) {
            continue;
        }
        $src = (string) file_get_contents($file);
        foreach (["table('shared_outbox_events'", 'table("shared_outbox_events"', "from('shared_outbox_events'"] as $needle) {
            if (str_contains($src, $needle)) {
                $offenders[] = $rel.' :: '.$needle;
            }
        }
    }
    expect($offenders)->toBe([]);
})->group('arch');

// ── Module-boundary rules — active once a domain exists ────────────────

if ($hasDomains) {
    arch('a domain layer never touches the DB facade or a query builder (IC §9)')
        ->expect(['App\Domains\*\Domain', 'App\Domains\*\Application', 'App\Domains\*\Presentation'])
        ->not->toUse([
            'Illuminate\Support\Facades\DB',
            'Illuminate\Database\Query\Builder',
            'Illuminate\Database\Eloquent\Builder',
        ])
        ->group('arch');

    // IC §9 / §17.3 — repository & reader INTERFACES (in Domain/) never expose an
    // Eloquent Builder or Collection across the boundary.
    arch('domain repository/reader interfaces do not leak Eloquent types (IC §9)')
        ->expect('App\Domains\*\Domain')
        ->not->toUse([
            'Illuminate\Database\Eloquent\Builder',
            'Illuminate\Database\Query\Builder',
            'Illuminate\Database\Eloquent\Collection',
        ])
        ->group('arch');

    // IC §7.0 — DOMAIN events are POPOs delivered by outbox+relay, never routed
    // through Laravel's dispatcher. Scoped to the layers that emit/handle domain
    // events; a ServiceProvider bridging framework auth/queue events is allowed.
    arch('domain layers do not route domain events through Laravel (IC §7.0)')
        ->expect(['App\Domains\*\Domain', 'App\Domains\*\Application'])
        ->not->toUse(['Illuminate\Support\Facades\Event', 'event'])
        ->group('arch');

    arch('SystemContext is not reachable from HTTP / Application (IC §11.4)')
        ->expect('App\Shared\Tenancy\SystemContext')
        ->not->toBeUsedIn(['App\Domains\*\Presentation', 'App\Domains\*\Application', 'App\Http\Middleware'])
        ->group('arch');

    arch('controllers only orchestrate HTTP (IC §15)')
        ->expect('App\Domains\*\Presentation\Http\Controller')
        ->not->toUse([
            'Illuminate\Support\Facades\DB',
            'App\Domains\*\Domain\Model',
        ])
        ->group('arch');

    // IC §5 / §17.3 — command DTOs are final readonly; handlers are final with a
    // single public __invoke; a handler is constructed only by the bus (or a
    // unit test), never by application code.
    test('commands are final readonly and handlers are final single-__invoke (IC §5)', function () use ($appDir): void {
        $offenders = [];
        $files = array_merge(
            rglob($appDir.'/Domains', '*.php'),
            rglob($appDir.'/Platform', '*.php'),
        );
        foreach ($files as $file) {
            if (! preg_match('#/Application/(Command|Query)/#', $file)) {
                continue;
            }
            $src = (string) file_get_contents($file);
            if (! preg_match('/\b(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/', $src, $m)) {
                continue;
            }
            $name = $m[1];
            $isHandler = str_ends_with($name, 'Handler');
            $rel = str_replace($appDir.'/', '', $file);

            if (! preg_match('/final\s+(?:readonly\s+)?class/', $src)) {
                $offenders[] = "{$rel} :: {$name} is not final";
            }
            if (! $isHandler && ! str_contains($src, 'final readonly class') && ! str_contains($src, 'readonly class')) {
                $offenders[] = "{$rel} :: {$name} (command/query DTO) is not readonly";
            }
            if ($isHandler) {
                // §5: a handler MAY have __construct (the §5 skeleton injects its
                // repository there — §5.1 reflects it); its only OTHER public
                // method must be __invoke. A raw `substr_count('public function ')`
                // wrongly rejects `public function __construct(...)` next to
                // `public function __invoke(...)`, so reflect the real class.
                $fqcn = preg_match('/^namespace\s+([^;]+);/m', $src, $ns)
                    ? trim($ns[1]).'\\'.$name
                    : null;

                if ($fqcn !== null && class_exists($fqcn)) {
                    $extraPublic = array_values(array_diff(
                        array_map(
                            static fn (ReflectionMethod $method): string => $method->getName(),
                            (new ReflectionClass($fqcn))->getMethods(ReflectionMethod::IS_PUBLIC),
                        ),
                        ['__construct', '__invoke'],
                    ));

                    if ($extraPublic !== []) {
                        $offenders[] = "{$rel} :: {$name} exposes public method(s) besides __invoke: ".implode(', ', $extraPublic).' (§5)';
                    }
                }
            }
            if ($isHandler && ! str_contains($src, 'public function __invoke(')) {
                $offenders[] = "{$rel} :: {$name} has no public __invoke (§5)";
            }
        }
        expect($offenders)->toBe([]);
    })->group('arch');

    test('a command handler is never constructed outside the bus (IC §5)', function () use ($appDir): void {
        $offenders = [];
        foreach (rglob($appDir, '*.php') as $file) {
            if (str_contains($file, '/Shared/Bus/')) {
                continue;
            }
            if (preg_match('/\bnew\s+\\\\?[A-Za-z0-9_\\\\]*Handler\s*\(/', (string) file_get_contents($file))) {
                $offenders[] = str_replace($appDir.'/', '', $file);
            }
        }
        expect($offenders)->toBe([]);
    })->group('arch');

    // IC §2.1 — a module may import another module ONLY through its `Contracts\`
    // namespace. Checked per pair against the real tree.
    test('a module imports another module only via its Contracts namespace (IC §2.1)', function () use ($appDir): void {
        $modules = array_map('basename', glob($appDir.'/Domains/*', GLOB_ONLYDIR) ?: []);
        $offenders = [];

        foreach (rglob($appDir.'/Domains', '*.php') as $file) {
            $self = null;
            if (preg_match('#/Domains/([A-Za-z]+)/#', $file, $m)) {
                $self = $m[1];
            }
            $src = (string) file_get_contents($file);
            foreach ($modules as $other) {
                if ($other === $self) {
                    continue;
                }
                if (preg_match_all('/^use (App\\\\Domains\\\\'.$other.'\\\\[A-Za-z0-9_\\\\]+);/m', $src, $mm)) {
                    foreach ($mm[1] as $import) {
                        if (! str_starts_with($import, 'App\\Domains\\'.$other.'\\Contracts\\')) {
                            $offenders[] = str_replace($appDir.'/', '', $file).' :: '.$import;
                        }
                    }
                }
            }
        }

        expect($offenders)->toBe([]);
    })->group('arch');

    // ADR-130 B — the Encounter aggregate carries only the coarse care-context
    // location (department_id / unit_id). room_id / bed_id, operational location
    // and movement history belong to Queueing & Patient Flow / Bed & Ward
    // Management, not Encounter. `enc_encounters` must not grow those columns.
    test('enc_encounters has no room/bed/location-history column (ADR-130 B)', function () use ($appDir): void {
        $offenders = [];
        foreach (glob($appDir.'/Domains/Encounter/Database/Migrations/*enc_encounters*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            foreach (["'room_id'", "'bed_id'", "'location_history'", "'current_location'"] as $needle) {
                if (str_contains($src, $needle)) {
                    $offenders[] = basename($file).' :: '.$needle;
                }
            }
        }
        expect($offenders)->toBe([]);
    })->group('arch');

    // IC §12 — `org_facility_capabilities` is the Organization module's table.
    // The one place outside it that may read it is the shared capability service.
    test('org_facility_capabilities is queried only in Organization or App\\Shared\\Capability', function () use ($appDir): void {
        $offenders = [];
        foreach (rglob($appDir, '*.php') as $file) {
            if (str_contains($file, '/Domains/Organization/') || str_contains($file, '/Shared/Capability/')) {
                continue;
            }
            if (str_contains((string) file_get_contents($file), 'org_facility_capabilities')) {
                $offenders[] = str_replace($appDir.'/', '', $file);
            }
        }
        expect($offenders)->toBe([]);
    })->group('arch');

}

if ($hasContractEvents) {
    arch('domain events are immutable, final, and not broadcastable (IC §7.0/§7.1)')
        ->expect('App\Domains\*\Contracts\Event')
        ->toBeFinal()
        ->toBeReadonly()
        ->not->toImplement('Illuminate\Contracts\Broadcasting\ShouldBroadcast')
        ->group('arch');
}

if ($hasPlatform) {
    arch('platform services own no authoritative business truth')
        ->expect('App\Platform')
        ->not->toUse(['Illuminate\Support\Facades\Event', 'event'])
        ->group('arch');

    test('domains import platform services only via Contracts (AGENTS §5, IC §2.1)', function () use ($appDir): void {
        $offenders = [];
        foreach (rglob($appDir.'/Domains', '*.php') as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match_all('/^use (App\\\\Platform\\\\[A-Za-z0-9_\\\\]+);/m', $src, $mm)) {
                foreach ($mm[1] as $import) {
                    if (! str_contains($import, '\\Contracts\\')) {
                        $offenders[] = str_replace($appDir.'/', '', $file).' :: '.$import;
                    }
                }
            }
        }
        expect($offenders)->toBe([]);
    })->group('arch');

    test('platform services import domains only via Contracts (AGENTS §5, IC §2.1)', function () use ($appDir): void {
        $offenders = [];
        foreach (rglob($appDir.'/Platform', '*.php') as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match_all('/^use (App\\\\Domains\\\\[A-Za-z0-9_\\\\]+);/m', $src, $mm)) {
                foreach ($mm[1] as $import) {
                    if (! str_contains($import, '\\Contracts\\')) {
                        $offenders[] = str_replace($appDir.'/', '', $file).' :: '.$import;
                    }
                }
            }
        }
        expect($offenders)->toBe([]);
    })->group('arch');

    test('cross-platform services import each other only via Contracts (AGENTS §5, IC §2.1)', function () use ($appDir): void {
        $platformModules = array_map('basename', glob($appDir.'/Platform/*', GLOB_ONLYDIR) ?: []);
        $offenders = [];
        foreach (rglob($appDir.'/Platform', '*.php') as $file) {
            $self = null;
            if (preg_match('#/Platform/([A-Za-z]+)/#', $file, $m)) {
                $self = $m[1];
            }
            $src = (string) file_get_contents($file);
            foreach ($platformModules as $other) {
                if ($other === $self) {
                    continue;
                }
                if (preg_match_all('/^use (App\\\\Platform\\\\'.$other.'\\\\[A-Za-z0-9_\\\\]+);/m', $src, $mm)) {
                    foreach ($mm[1] as $import) {
                        if (! str_starts_with($import, 'App\\Platform\\'.$other.'\\Contracts\\')) {
                            $offenders[] = str_replace($appDir.'/', '', $file).' :: '.$import;
                        }
                    }
                }
            }
        }
        expect($offenders)->toBe([]);
    })->group('arch');
}
