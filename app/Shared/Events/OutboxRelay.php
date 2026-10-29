<?php

declare(strict_types=1);

namespace App\Shared\Events;

use App\Shared\Jobs\DeadLetterHandler;
use App\Shared\Tenancy\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The transactional-outbox relay (IMPLEMENTATION-CONTRACTS.md §7.2, §11.4).
 *
 * It does **not** bypass RLS (§11.4): it iterates tenants (from the non-RLS
 * `shared_tenants` registry) and, for each, runs one `Tenancy::run($tenantId)`
 * batch — claiming unpublished rows `ORDER BY id` (UUIDv7 → chronological, keeps
 * per-aggregate order) `FOR UPDATE SKIP LOCKED` (safe with multiple workers),
 * dispatching a queued {@see DispatchListenerJob} per registered listener, then
 * marking the row published.
 *
 * On failure: `attempts++`, `last_error`, `available_at = now() + backoff`.
 * After {@see self::MAX_ATTEMPTS} the event moves to `shared_dead_letters`
 * (+ audit) and the row is retired.
 */
final class OutboxRelay extends Command
{
    protected $signature = 'outbox:relay {--batch=100 : Max rows to claim per tenant} {--once : Process one pass and exit}';

    protected $description = 'Relay unpublished outbox events to their registered listeners.';

    public const MAX_ATTEMPTS = 8;

    public function handle(DeadLetterHandler $deadLetters): int
    {
        $batch = max(1, (int) $this->option('batch'));

        /** @var list<string> $tenantIds */
        $tenantIds = DB::table('shared_tenants')->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $relayed = 0;

        foreach ($tenantIds as $tenantId) {
            $relayed += Tenancy::run($tenantId, function () use ($batch, $deadLetters): int {
                $rows = DB::table('shared_outbox_events')
                    ->whereNull('published_at')
                    ->where('available_at', '<=', now())
                    ->orderBy('id')
                    ->limit($batch)
                    ->lock('for update skip locked')
                    ->get();

                foreach ($rows as $row) {
                    $this->relayRow($row, $deadLetters);
                }

                return $rows->count();
            });
        }

        if ($relayed > 0) {
            $this->info(sprintf('Relayed %d outbox event(s).', $relayed));
        }

        return self::SUCCESS;
    }

    private function relayRow(stdClass $row, DeadLetterHandler $deadLetters): void
    {
        try {
            $event = $this->hydrate($row);

            foreach (EventRegistry::listeners((string) $row->event_class) as $listenerClass) {
                DispatchListenerJob::dispatch(
                    (string) $row->tenant_id,
                    $listenerClass,
                    $event,
                    null,
                    $row->correlation_id !== null ? (string) $row->correlation_id : null,
                    (string) $row->id, // the delivered event is the cause of anything the listener does
                );
            }

            DB::table('shared_outbox_events')->where('id', $row->id)->update(['published_at' => now()]);
        } catch (Throwable $e) {
            $attempts = (int) $row->attempts + 1;

            if ($attempts >= self::MAX_ATTEMPTS) {
                $deadLetters->record(
                    tenantId: (string) $row->tenant_id,
                    sourceType: 'outbox_event',
                    sourceId: (string) $row->id,
                    payload: json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR),
                    error: $e,
                    correlationId: $row->correlation_id !== null ? (string) $row->correlation_id : null,
                    causationId: $row->causation_id !== null ? (string) $row->causation_id : null,
                );

                DB::table('shared_outbox_events')->where('id', $row->id)->update([
                    'attempts' => $attempts,
                    'last_error' => $e->getMessage(),
                    'published_at' => now(), // retired — dead-lettered
                ]);
            } else {
                DB::table('shared_outbox_events')->where('id', $row->id)->update([
                    'attempts' => $attempts,
                    'last_error' => $e->getMessage(),
                    'available_at' => CarbonImmutable::now()->addSeconds($this->backoff($attempts)),
                ]);
            }

            Log::error('Outbox relay failed for event '.$row->id, [
                'event_id' => $row->id,
                'event_class' => $row->event_class,
                'tenant_id' => $row->tenant_id,
                'attempts' => $attempts,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Resolve `event_class` → hydrate the readonly event from its payload
     * (§7.2). A missing class or a payload that no longer fits the constructor
     * is a relay failure → retry/backoff, then dead-letter.
     */
    private function hydrate(stdClass $row): object
    {
        $class = (string) $row->event_class;

        if (! class_exists($class)) {
            throw new RuntimeException("Outbox event class [{$class}] does not exist.");
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);

        return new $class(...$payload);
    }

    private function backoff(int $attempts): int
    {
        return min((int) (5 * (3 ** ($attempts - 1))), 3600);
    }
}
