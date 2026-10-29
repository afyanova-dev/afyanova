<?php

declare(strict_types=1);

namespace App\Shared\Bus;

use App\Shared\Bus\Middleware\AuthorizeQuery;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Pipeline\Pipeline;
use InvalidArgumentException;
use RuntimeException;

/**
 * Dispatches queries (IMPLEMENTATION-CONTRACTS.md §6.1):
 *   1. tenant context — inherited from the request's `Tenancy::run` (§11.3);
 *      the QueryBus does NOT open its own transaction
 *   2. authorize     — protected reads (§6.2), via `#[ProtectedQuery]`
 *   3. execute the reader
 *   4. return the DTO / paginator
 */
final class QueryBus
{
    /** @var array<class-string, class-string> */
    private static array $handlers = [];

    public function __construct(private readonly Container $container) {}

    private function tenantContext(): TenantContext
    {
        return $this->container->make(TenantContext::class);
    }

    /**
     * @param  array<class-string, class-string>  $map  query => handler
     */
    public static function map(array $map): void
    {
        foreach ($map as $query => $handler) {
            self::$handlers[$query] = $handler;
        }
    }

    public static function flush(): void
    {
        self::$handlers = [];
    }

    public function dispatch(object $query): mixed
    {
        $queryClass = $query::class;

        if (! isset(self::$handlers[$queryClass])) {
            throw new InvalidArgumentException("No handler registered for query [{$queryClass}].");
        }

        if (! $this->tenantContext()->hasTenant()) {
            throw new RuntimeException(
                "Cannot dispatch [{$queryClass}] — no TenantContext established (IC §6.1). ".
                'A query runs inside the request/job tenant scope; it does not open its own.'
            );
        }

        $handlerClass = self::$handlers[$queryClass];

        return (new Pipeline($this->container))
            ->send($query)
            ->through([AuthorizeQuery::class])
            ->then(fn (object $query): mixed => $this->container->make($handlerClass)($query));
    }
}
