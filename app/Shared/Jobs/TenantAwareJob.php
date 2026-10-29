<?php

declare(strict_types=1);

namespace App\Shared\Jobs;

use App\Shared\Support\CorrelationId;
use App\Shared\Tenancy\TenantContext;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Base class for every tenant-scoped queued job (IMPLEMENTATION-CONTRACTS.md §14).
 *
 * Captures `tenantId`, `facilityId`, `correlationId`, `causationId` at dispatch
 * (from {@see TenantContext} when not passed explicitly); re-establishes the
 * context via {@see TenantContextScope::run} in `handle()`. `after_commit` so a
 * job dispatched inside a command transaction runs only after it commits.
 */
abstract class TenantAwareJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public readonly string $tenantId;

    public readonly ?string $facilityId;

    public readonly string $correlationId;

    public readonly ?string $causationId;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        ?string $tenantId = null,
        ?string $facilityId = null,
        ?string $correlationId = null,
        ?string $causationId = null,
    ) {
        $this->afterCommit = true;

        $context = app(TenantContext::class);

        $this->tenantId = $tenantId
            ?? ($context->hasTenant() ? $context->tenantId() : '');
        $this->facilityId = $facilityId
            ?? ($context->hasTenant() ? $context->facilityId() : null);
        $this->correlationId = $correlationId ?? CorrelationId::get();
        $this->causationId = $causationId;
    }

    public function handle(): void
    {
        if ($this->tenantId === '') {
            throw new RuntimeException(static::class.' was dispatched without a tenant context.');
        }

        TenantContextScope::run($this->tenantId, $this->facilityId, function (): void {
            CorrelationId::set($this->correlationId);
            $this->execute();
        });
    }

    abstract protected function execute(): void;

    /**
     * Failure hierarchy (IC §14): (1) persist to `shared_dead_letters` + audit,
     * inside the job's tenant context so the RLS `WITH CHECK` passes;
     * (2) if that DB write fails, emit a structured `critical` log line.
     */
    public function failed(Throwable $e): void
    {
        try {
            if ($this->tenantId === '') {
                throw new RuntimeException('no tenant context on the failed job');
            }

            TenantContextScope::run($this->tenantId, $this->facilityId, function () use ($e): void {
                app(DeadLetterHandler::class)->record(
                    tenantId: $this->tenantId,
                    sourceType: static::class,
                    sourceId: $this->job?->getJobId() ?? $this->correlationId,
                    payload: ['job' => static::class],
                    error: $e,
                    correlationId: $this->correlationId,
                    causationId: $this->causationId,
                );
            });
        } catch (Throwable $deadLetterError) {
            Log::critical('Failed to record dead letter for job', [
                'job_class' => static::class,
                'tenant_id' => $this->tenantId,
                'correlation_id' => $this->correlationId,
                'original_error' => $e->getMessage(),
                'dead_letter_error' => $deadLetterError->getMessage(),
            ]);
        }
    }
}
