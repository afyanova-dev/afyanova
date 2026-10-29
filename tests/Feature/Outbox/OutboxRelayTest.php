<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Models\Tenant;
use App\Shared\Events\DispatchListenerJob;
use App\Shared\Events\EventRegistry;
use App\Shared\Events\OutboxRelay;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 | PHASE 4 — the outbox relay (IC §7.2, §11.4). The relay does NOT run listeners;
 | it iterates tenants, claims unpublished rows under Tenancy::run (no
 | SystemContext), dispatches a queued DispatchListenerJob per listener, and
 | marks the row published. Listener execution + its failure handling belong to
 | the job (see DispatchListenerJob / TenantAwareJob).
 */

uses(RefreshDatabase::class);

final class RelayEvent
{
    public function __construct(public string $message) {}
}

final class RelayListener
{
    public function __invoke(RelayEvent $event): void {}
}

beforeEach(function () {
    EventRegistry::flush();
});

function seedOutboxRow(string $tenantId, string $eventClass, array $payload, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    Tenancy::run($tenantId, fn () => DB::table('shared_outbox_events')->insert(array_merge([
        'id' => $id,
        'tenant_id' => $tenantId,
        'event_name' => 'relay.event',
        'event_class' => $eventClass,
        'payload' => json_encode($payload),
        'correlation_id' => (string) Str::uuid7(),
        'available_at' => now()->subMinute(),
        'created_at' => now(),
        'attempts' => 0,
    ], $overrides)));

    return $id;
}

it('dispatches a queued listener job per tenant and marks the row published', function () {
    Queue::fake();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    EventRegistry::listen(RelayEvent::class, RelayListener::class);

    $a = seedOutboxRow($tenantA->id, RelayEvent::class, ['message' => 'A']);
    $b = seedOutboxRow($tenantB->id, RelayEvent::class, ['message' => 'B']);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    Queue::assertPushed(DispatchListenerJob::class, 2);
    Queue::assertPushed(DispatchListenerJob::class, fn (DispatchListenerJob $job): bool => $job->tenantId === $tenantA->id
        && $job->listenerClass === RelayListener::class
        && $job->event instanceof RelayEvent
        && $job->event->message === 'A'
        && $job->causationId === $a);

    $rowA = Tenancy::run($tenantA->id, fn () => DB::table('shared_outbox_events')->find($a));
    $rowB = Tenancy::run($tenantB->id, fn () => DB::table('shared_outbox_events')->find($b));
    expect($rowA->published_at)->not->toBeNull()
        ->and($rowB->published_at)->not->toBeNull();
});

it('writes no audit-bypass row (IC §11.4 — the relay does not use SystemContext)', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    EventRegistry::listen(RelayEvent::class, RelayListener::class);
    seedOutboxRow($tenant->id, RelayEvent::class, ['message' => 'x']);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    $bypass = DB::connection('pgsql_raw')->table('shared_audit_log')->where('action', 'system.rls_bypass')->count();
    expect($bypass)->toBe(0);
});

it('dead-letters a row it cannot hydrate, after MAX_ATTEMPTS', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();
    EventRegistry::listen(RelayEvent::class, RelayListener::class);

    // event_class does not exist → relayRow() throws on hydrate
    $id = seedOutboxRow($tenant->id, 'App\\Does\\Not\\Exist', ['message' => 'x'], [
        'attempts' => OutboxRelay::MAX_ATTEMPTS - 1,
    ]);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    $row = Tenancy::run($tenant->id, fn () => DB::table('shared_outbox_events')->find($id));
    expect($row->attempts)->toBe(OutboxRelay::MAX_ATTEMPTS)
        ->and($row->published_at)->not->toBeNull(); // retired

    $deadLetter = Tenancy::run($tenant->id, fn () => DB::table('shared_dead_letters')->where('source_id', $id)->first());
    expect($deadLetter)->not->toBeNull()
        ->and($deadLetter->source_type)->toBe('outbox_event');

    $this->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $tenant->id,
        'action' => 'dead_letter.created',
    ]);
});

it('backs off a row it cannot hydrate before MAX_ATTEMPTS', function () {
    Queue::fake();
    $tenant = Tenant::factory()->create();

    $id = seedOutboxRow($tenant->id, 'App\\Does\\Not\\Exist', ['message' => 'x'], ['attempts' => 1]);

    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    [$row, $deferred] = Tenancy::run($tenant->id, fn (): array => [
        DB::table('shared_outbox_events')->find($id),
        DB::table('shared_outbox_events')->where('id', $id)->where('available_at', '>', now())->exists(),
    ]);

    expect($row->attempts)->toBe(2)
        ->and($row->published_at)->toBeNull()
        ->and($row->last_error)->not->toBeNull()
        ->and($deferred)->toBeTrue();   // backed off — not eligible until later
});
