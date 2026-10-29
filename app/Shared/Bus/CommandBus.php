<?php

declare(strict_types=1);

namespace App\Shared\Bus;

use App\Shared\Bus\Middleware\AuthorizeCommand;
use App\Shared\Bus\Middleware\ClaimIdempotencyKey;
use App\Shared\Bus\Middleware\WriteAuditRow;
use App\Shared\Support\Causation;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;
use InvalidArgumentException;
use RuntimeException;

/**
 * Dispatches domain commands through the canonical middleware pipeline
 * (IMPLEMENTATION-CONTRACTS.md §5). One transaction, opened by `Tenancy::run`:
 *   1. RLS context (Tenancy::run)  2. authorize  3. claim idempotency
 *   4. handle  5. write audit  6. write outbox (in the handler's repository)
 *   7. finalise idempotency   →  commit  →  8. return the Result DTO.
 */
final class CommandBus
{
    /** @var array<class-string, class-string> */
    private static array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /** Resolved per dispatch — the request/job/scope rebinds this (IC §11.1). */
    private function tenantContext(): TenantContext
    {
        return $this->container->make(TenantContext::class);
    }

    /**
     * @param  array<class-string, class-string>  $map  command => handler
     */
    public static function map(array $map): void
    {
        foreach ($map as $command => $handler) {
            self::$handlers[$command] = $handler;
        }
    }

    /** @return class-string|null the handler registered for a command */
    public static function handlerFor(string $commandClass): ?string
    {
        return self::$handlers[$commandClass] ?? null;
    }

    public static function flush(): void
    {
        self::$handlers = [];
    }

    /**
     * @param  string|null  $causationId  the id of the command/event that caused
     *                                    this one (§19) — a listener passes the
     *                                    delivered event's `eventId`.
     */
    public function dispatch(object $command, ?string $causationId = null): mixed
    {
        $commandClass = $command::class;

        if (! isset(self::$handlers[$commandClass])) {
            throw new InvalidArgumentException("No handler registered for command [{$commandClass}].");
        }

        $context = $this->tenantContext();

        if (! $context->hasTenant()) {
            throw new RuntimeException(
                "Cannot dispatch [{$commandClass}] — no TenantContext established (IC §5, §11.1). ".
                'Dispatch from a request, a TenantAwareJob, or TenantContextScope::run().'
            );
        }

        $tenantId = $context->tenantId();
        $handlerClass = self::$handlers[$commandClass];

        return Causation::within($causationId ?? Causation::get(), fn (): mixed => Tenancy::run(
            $tenantId,
            fn (): mixed => (new Pipeline($this->container))
                ->send($command)
                ->through([
                    AuthorizeCommand::class,
                    ClaimIdempotencyKey::class,
                    WriteAuditRow::class,
                ])
                ->then(fn (object $command): mixed => $this->container->make($handlerClass)($command)),
        ));
    }
}
