<?php

declare(strict_types=1);

namespace Tests\Feature\Bus;

use App\Models\Tenant;
use App\Models\User;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\CommandBus;
use App\Shared\Idempotency\IdempotencyConflict;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 4 gate — CommandBus Integration (blueprint.md)
 */

uses(RefreshDatabase::class);

final class DummyCommand implements Auditable
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $idempotencyKey,
        public readonly string $actorId,
    ) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'dummy.execute',
            actorId: $this->actorId,
            targetType: 'dummy',
            targetId: 'target-123',
            reason: 'testing bus',
        );
    }
}

final readonly class DummyResult
{
    public function __construct(public bool $executed, public string $key) {}
}

final class DummyCommandHandler
{
    public function __invoke(DummyCommand $command): DummyResult
    {
        return new DummyResult(executed: true, key: $command->idempotencyKey);
    }
}

beforeEach(function () {
    CommandBus::flush();
});

test('command bus handles command with idempotency and audit logs', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant->id)->create();
    $key = (string) Str::uuid7();

    CommandBus::map([
        DummyCommand::class => DummyCommandHandler::class,
    ]);

    $command = new DummyCommand(
        tenantId: $tenant->id,
        idempotencyKey: $key,
        actorId: $user->id,
    );

    $bus = app(CommandBus::class);

    // Initial dispatch
    $result = TenantContextScope::run($tenant->id, null, function () use ($bus, $command) {
        return $bus->dispatch($command);
    });

    expect($result)->toBeInstanceOf(DummyResult::class)
        ->and($result->executed)->toBeTrue()
        ->and($result->key)->toBe($key);

    // 1. Audit row written to shared_audit_log
    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenant->id,
        'action' => 'dummy.execute',
        'actor_id' => $user->id,
        'target_type' => 'dummy',
        'target_id' => 'target-123',
        'reason' => 'testing bus',
    ]);

    // 2. Idempotency row written to shared_idempotency_keys
    $this->assertDatabaseHas('shared_idempotency_keys', [
        'tenant_id' => $tenant->id,
        'scope' => DummyCommand::class,
        'key' => $key,
        'status' => 'completed',
    ]);

    // 3. Dispatching the identical command replays the stored result — SAME
    //    type, same value (§5 step 7 — "return its stored result")
    $result2 = TenantContextScope::run($tenant->id, null, function () use ($bus, $command) {
        return $bus->dispatch($command);
    });

    expect($result2)->toBeInstanceOf(DummyResult::class)
        ->and($result2)->toEqual($result);
});

test('command bus throws IdempotencyConflict for in-progress key', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant->id)->create();
    $key = (string) Str::uuid7();

    // Manually insert an IN_PROGRESS row inside tenant context
    Tenancy::run($tenant->id, function () use ($tenant, $key) {
        DB::table('shared_idempotency_keys')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'scope' => DummyCommand::class,
            'key' => $key,
            'status' => 'in_progress',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    CommandBus::map([
        DummyCommand::class => DummyCommandHandler::class,
    ]);

    $command = new DummyCommand(
        tenantId: $tenant->id,
        idempotencyKey: $key,
        actorId: $user->id,
    );

    $bus = app(CommandBus::class);

    TenantContextScope::run($tenant->id, null, function () use ($bus, $command) {
        $bus->dispatch($command);
    });
})->throws(IdempotencyConflict::class);
