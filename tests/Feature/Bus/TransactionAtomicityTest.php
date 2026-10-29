<?php

declare(strict_types=1);

namespace Tests\Feature\Bus;

use App\Models\Tenant;
use App\Models\User;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/*
 | IC §5 / §7.2 / §18 — everything a command writes (aggregate rows, outbox
 | events, the audit row) lives in the ONE transaction the bus opens
 | (`Tenancy::run`). If the command fails, none of it survives. These tests
 | prove the rollback for real — no manual cleanup.
 */

uses(RefreshDatabase::class);

final readonly class OrderCreatedForAtomicity
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $orderId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}

final class AtomicityOrder extends AggregateRoot
{
    protected $table = 'test_atomicity_orders';

    protected $fillable = ['id', 'tenant_id', 'label', 'status'];

    public static function open(string $tenantId, string $label): self
    {
        $order = new self([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'label' => $label,
            'status' => 'OPEN',
        ]);

        $order->recordThat(new OrderCreatedForAtomicity(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            orderId: (string) $order->id,
            occurredAt: now()->toISOString(),
            correlationId: (string) Str::uuid7(),
        ));

        return $order;
    }
}

final class AtomicityOrderRepository extends EloquentRepository
{
    protected function model(): string
    {
        return AtomicityOrder::class;
    }
}

/** Persists the aggregate + outbox, then throws — nothing may survive. */
final readonly class CreateOrderThenFail implements Auditable
{
    public function __construct(public string $label) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(action: 'atomicity.order_created', targetType: 'order', reason: $this->label);
    }
}

final class CreateOrderThenFailHandler
{
    public function __construct(private readonly AtomicityOrderRepository $orders) {}

    public function __invoke(CreateOrderThenFail $command): never
    {
        $order = AtomicityOrder::open(app(TenantContext::class)->tenantId(), $command->label);
        $this->orders->save($order); // persists the row + writes the outbox event

        throw new RuntimeException('handler blew up after save');
    }
}

/**
 * The handler SUCCEEDS (mutation + outbox) and `WriteAuditRow` writes the audit
 * row — then the pipeline fails a step later, in the SAME transaction, when
 * `ClaimIdempotencyKey` tries to serialise a result it cannot. Proves the audit
 * row written earlier is rolled back with everything else (§18).
 */
final readonly class CreateOrderFailPostAudit implements Auditable
{
    public function __construct(public string $label, public string $idempotencyKey) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(action: 'atomicity.order_created', targetType: 'order', reason: $this->label);
    }
}

final class CreateOrderFailPostAuditHandler
{
    public function __construct(private readonly AtomicityOrderRepository $orders) {}

    /** @return array<string, mixed> a result the idempotency store cannot serialize */
    public function __invoke(CreateOrderFailPostAudit $command): array
    {
        $order = AtomicityOrder::open(app(TenantContext::class)->tenantId(), $command->label);
        $this->orders->save($order);

        return ['unserializable' => static fn () => null];
    }
}

beforeEach(function () {
    CommandBus::flush();

    Schema::create('test_atomicity_orders', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->uuid('tenant_id');
        $t->string('label');
        $t->string('status');
        $t->unsignedBigInteger('version')->default(0);
        $t->timestampsTz();
    });

    CommandBus::map([
        CreateOrderThenFail::class => CreateOrderThenFailHandler::class,
        CreateOrderFailPostAudit::class => CreateOrderFailPostAuditHandler::class,
    ]);
});

test('a failing handler rolls back the aggregate AND its outbox event together (IC §7.2)', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAs(User::factory()->forTenant($tenant->id)->create());

    $before = Tenancy::run($tenant->id, fn () => [
        DB::table('test_atomicity_orders')->count(),
        DB::table('shared_outbox_events')->count(),
    ]);

    expect(fn () => TenantContextScope::run($tenant->id, null, fn () => app(CommandBus::class)->dispatch(new CreateOrderThenFail('widget'))))
        ->toThrow(RuntimeException::class);

    $after = Tenancy::run($tenant->id, fn () => [
        DB::table('test_atomicity_orders')->count(),
        DB::table('shared_outbox_events')->count(),
        DB::table('shared_audit_log')->where('action', 'atomicity.order_created')->count(),
    ]);

    expect($after[0])->toBe($before[0])   // no aggregate row
        ->and($after[1])->toBe($before[1]) // no outbox event
        ->and($after[2])->toBe(0);         // no audit row
});

test('a post-audit failure in the same transaction rolls back the audit row too (IC §18)', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAs(User::factory()->forTenant($tenant->id)->create());

    expect(fn () => TenantContextScope::run($tenant->id, null, fn () => app(CommandBus::class)->dispatch(
        new CreateOrderFailPostAudit('gadget', (string) Str::uuid7())
    )))->toThrow(\Exception::class);   // Serialization of 'Closure' is not allowed — a post-audit failure

    $state = Tenancy::run($tenant->id, fn () => [
        'orders' => DB::table('test_atomicity_orders')->count(),
        'outbox' => DB::table('shared_outbox_events')->where('event_class', OrderCreatedForAtomicity::class)->count(),
        'audit' => DB::table('shared_audit_log')->where('action', 'atomicity.order_created')->count(),
        'idem' => DB::table('shared_idempotency_keys')->where('scope', CreateOrderFailPostAudit::class)->count(),
    ]);

    expect($state['orders'])->toBe(0)   // aggregate rolled back
        ->and($state['outbox'])->toBe(0) // outbox event rolled back
        ->and($state['audit'])->toBe(0)  // audit row — written earlier in the txn — rolled back
        ->and($state['idem'])->toBe(0);  // even the in_progress idempotency claim rolled back
});
