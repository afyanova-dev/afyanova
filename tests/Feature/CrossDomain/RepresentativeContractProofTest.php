<?php

declare(strict_types=1);

namespace Tests\Feature\CrossDomain;

use App\Models\Tenant;
use App\Models\User;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use App\Shared\Bus\CommandBus;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Events\DispatchListenerJob;
use App\Shared\Events\EventRegistry;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 | PHASE 4 gate — one representative cross-domain interaction proving the whole
 | contract mechanism (blueprint.md §9). TWO distinct aggregates in TWO distinct
 | (simulated) bounded contexts; the interaction crosses ONLY through the event
 | + the outbox, never a shared table:
 |
 |   Billing.OpenInvoice  ─command/bus──▶  Billing\Invoice aggregate (+ version)
 |        └─ records InvoiceOpened ──repository.save()──▶  outbox (one txn, §7.2)
 |   OutboxRelay (per-tenant, no SystemContext, §11.4)
 |        └─ dispatches a queued DispatchListenerJob (§7.2)
 |   Pharmacy.OnInvoiceOpened listener  ─command/bus──▶  Pharmacy.DispenseForInvoice
 |        (plain command — event-originated, Auditable; idempotency keyed on eventId)
 |        └─ Pharmacy\Dispensation aggregate created, audit row (causation = eventId)
 |   redelivery → idempotent no-op ·  two conflicting saves → one ConcurrencyConflict
 */

uses(RefreshDatabase::class);

// ── Billing (producer) ───────────────────────────────────────────────

final readonly class InvoiceOpened
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $invoiceId,
        public int $amountMinor,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}

final class Invoice extends AggregateRoot
{
    protected $table = 'test_bill_invoices';

    protected $fillable = ['id', 'tenant_id', 'amount_minor', 'status'];

    public static function open(string $tenantId, int $amountMinor): self
    {
        $invoice = new self([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'amount_minor' => $amountMinor,
            'status' => 'OPEN',
        ]);

        $invoice->recordThat(new InvoiceOpened(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            invoiceId: (string) $invoice->id,
            amountMinor: $amountMinor,
            occurredAt: now()->toISOString(),
            correlationId: (string) Str::uuid7(),
        ));

        return $invoice;
    }
}

final class InvoiceRepository extends EloquentRepository
{
    protected function model(): string
    {
        return Invoice::class;
    }
}

final class InvoicePolicy
{
    public function open(User $actor): bool
    {
        return true; // any authenticated staff member may open an invoice
    }
}

final readonly class OpenInvoice implements AuthorizesAgainst
{
    public function __construct(public int $amountMinor) {}

    public function policyAbility(): string
    {
        return 'open';
    }

    public function subjectId(): ?string
    {
        return null; // create — class-level check
    }
}

final class OpenInvoiceHandler
{
    public function __construct(private readonly InvoiceRepository $invoices) {}

    /** @return array{invoiceId: string} */
    public function __invoke(OpenInvoice $command): array
    {
        $invoice = Invoice::open(app(TenantContext::class)->tenantId(), $command->amountMinor);
        $this->invoices->save($invoice);

        return ['invoiceId' => (string) $invoice->id];
    }
}

// ── Pharmacy (consumer) ──────────────────────────────────────────────

final class Dispensation extends AggregateRoot
{
    protected $table = 'test_rx_dispensations';

    protected $fillable = ['id', 'tenant_id', 'invoice_id', 'status'];

    public function markDispensed(): void
    {
        $this->status = 'DISPENSED';
    }
}

final class DispensationRepository extends EloquentRepository
{
    protected function model(): string
    {
        return Dispensation::class;
    }
}

/** Event-originated → a PLAIN command (not AuthorizesAgainst). Auditable. */
final readonly class DispenseForInvoice implements Auditable
{
    public function __construct(public string $invoiceId, public string $idempotencyKey) {}

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'pharmacy.dispensed_for_invoice',
            targetType: 'invoice',
            targetId: $this->invoiceId,
            reason: 'auto-dispense on invoice opened',
        );
    }
}

final class DispenseForInvoiceHandler
{
    public function __construct(private readonly DispensationRepository $dispensations) {}

    /** @return array{dispensationId: string} */
    public function __invoke(DispenseForInvoice $command): array
    {
        $dispensation = new Dispensation([
            'id' => (string) Str::uuid7(),
            'tenant_id' => app(TenantContext::class)->tenantId(),
            'invoice_id' => $command->invoiceId,
            'status' => 'PENDING',
        ]);
        $dispensation->markDispensed();
        $this->dispensations->save($dispensation);

        return ['dispensationId' => (string) $dispensation->id];
    }
}

final class OnInvoiceOpened
{
    public function __construct(private readonly CommandBus $bus) {}

    public function __invoke(InvoiceOpened $event): void
    {
        // idempotent: keyed on the event id (§7.3)
        $this->bus->dispatch(new DispenseForInvoice(
            invoiceId: $event->invoiceId,
            idempotencyKey: $event->eventId,
        ));
    }
}

// ── wiring ───────────────────────────────────────────────────────────

beforeEach(function () {
    EventRegistry::flush();
    CommandBus::flush();

    Schema::create('test_bill_invoices', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->uuid('tenant_id');
        $t->bigInteger('amount_minor');
        $t->string('status');
        $t->unsignedBigInteger('version')->default(0);
        $t->timestampsTz();
    });
    Schema::create('test_rx_dispensations', function (Blueprint $t) {
        $t->uuid('id')->primary();
        $t->uuid('tenant_id');
        $t->uuid('invoice_id');
        $t->string('status');
        $t->unsignedBigInteger('version')->default(0);
        $t->timestampsTz();
    });

    CommandBus::map([
        OpenInvoice::class => OpenInvoiceHandler::class,
        DispenseForInvoice::class => DispenseForInvoiceHandler::class,
    ]);
    EventRegistry::listen(InvoiceOpened::class, OnInvoiceOpened::class);
    Gate::policy(Invoice::class, InvoicePolicy::class);
});

test('representative cross-domain contract proof (phase 4 gate)', function () {
    Queue::fake([DispatchListenerJob::class]);

    $tenant = Tenant::factory()->create();
    $this->actingAs(User::factory()->forTenant($tenant->id)->create());

    // 1. Producer command through the bus → Billing aggregate + outbox (one txn)
    $created = TenantContextScope::run($tenant->id, null, fn () => app(CommandBus::class)->dispatch(new OpenInvoice(25_000)));
    $invoiceId = $created['invoiceId'];

    $invoiceRow = Tenancy::run($tenant->id, fn () => DB::table('test_bill_invoices')->find($invoiceId));
    expect((int) $invoiceRow->version)->toBe(0)->and($invoiceRow->status)->toBe('OPEN');

    $outboxRow = Tenancy::run($tenant->id, fn () => DB::table('shared_outbox_events')->where('event_class', InvoiceOpened::class)->first());
    expect($outboxRow)->not->toBeNull()->and($outboxRow->published_at)->toBeNull();
    $eventId = (string) $outboxRow->id;
    expect($outboxRow->event_name)->toBe('InvoiceOpened') // a real App\Domains\{X}\Contracts\Event\ class → "x.InvoiceOpened"
        ->and($outboxRow->event_class)->toBe(InvoiceOpened::class);

    // 2. Relay publishes the row and enqueues a DispatchListenerJob (not sync)
    $this->artisan('outbox:relay', ['--once' => true])->assertSuccessful();
    Queue::assertPushed(DispatchListenerJob::class, 1);
    expect(Tenancy::run($tenant->id, fn () => DB::table('shared_outbox_events')->find($eventId))->published_at)->not->toBeNull();

    // 3. Run the listener job as a real queue worker would (no interactive actor)
    auth()->guard()->logout();
    $job = collect(Queue::pushed(DispatchListenerJob::class))->first();
    $job->handle();

    // 4. Consumer (Pharmacy) aggregate — a DISTINCT table — was created + mutated
    $dispensation = Tenancy::run($tenant->id, fn () => DB::table('test_rx_dispensations')->where('invoice_id', $invoiceId)->first());
    expect($dispensation)->not->toBeNull()
        ->and($dispensation->status)->toBe('DISPENSED')
        ->and((int) $dispensation->version)->toBe(0); // created + marked + saved in one INSERT

    // 5. idempotency row completed, keyed on the event id
    $this->assertDatabaseHas('shared_idempotency_keys', [
        'tenant_id' => $tenant->id,
        'scope' => DispenseForInvoice::class,
        'key' => $eventId,
        'status' => 'completed',
    ]);

    // 6. audit row by the consumer command, causation = the delivered event
    $audit = Tenancy::run($tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'pharmacy.dispensed_for_invoice')->first());
    expect($audit)->not->toBeNull()
        ->and($audit->target_id)->toBe($invoiceId)
        ->and((string) $audit->causation_id)->toBe($eventId);

    // 7. redelivery of the same event → idempotent no-op
    $job->handle();
    expect(Tenancy::run($tenant->id, fn () => DB::table('test_rx_dispensations')->count()))->toBe(1)
        ->and(Tenancy::run($tenant->id, fn () => DB::table('shared_audit_log')->where('action', 'pharmacy.dispensed_for_invoice')->count()))->toBe(1);
});

test('the interaction crosses only through the event — no shared persistence, no handler cross-import', function () {
    // each handler depends on its OWN aggregate's repository and nothing from
    // the other domain
    $producerDeps = collect((new \ReflectionMethod(OpenInvoiceHandler::class, '__construct'))->getParameters())
        ->map(fn ($p) => $p->getType()?->getName());
    $consumerDeps = collect((new \ReflectionMethod(DispenseForInvoiceHandler::class, '__construct'))->getParameters())
        ->map(fn ($p) => $p->getType()?->getName());

    expect($producerDeps->all())->toBe([InvoiceRepository::class])
        ->and($consumerDeps->all())->toBe([DispensationRepository::class]);

    // the boundary object is a plain readonly POPO — no Eloquent, no DB
    $event = new \ReflectionClass(InvoiceOpened::class);
    expect($event->isReadonly())->toBeTrue()
        ->and($event->getParentClass())->toBeFalse();

    foreach ($event->getConstructor()->getParameters() as $param) {
        expect(in_array($param->getType()?->getName(), ['string', 'int'], true))->toBeTrue(
            "event field {$param->getName()} carries only a scalar (§7.1.1)"
        );
    }
});

test('two conflicting saves on a consumer aggregate → exactly one ConcurrencyConflict (§9.1)', function () {
    $tenant = Tenant::factory()->create();

    [$winners, $conflicts] = TenantContextScope::run($tenant->id, null, function () use ($tenant): array {
        $repo = app(DispensationRepository::class);
        $d = new Dispensation(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'invoice_id' => (string) Str::uuid7(), 'status' => 'PENDING']);
        $repo->save($d);

        $a = $repo->getById($d->id);
        $b = $repo->getById($d->id);

        $winners = $conflicts = 0;
        foreach ([$a, $b] as $instance) {
            try {
                $instance->markDispensed();
                $repo->save($instance);
                $winners++;
            } catch (ConcurrencyConflict) {
                $conflicts++;
            }
        }

        return [$winners, $conflicts];
    });

    expect($winners)->toBe(1)->and($conflicts)->toBe(1);
});
