<?php

declare(strict_types=1);

namespace App\Shared\Bus\Middleware;

use App\Shared\Bus\ProtectedQuery;
use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use ReflectionClass;

/**
 * QueryBus step 2 — authorize reads that expose PROTECTED data
 * (IMPLEMENTATION-CONTRACTS.md §6.1, §6.2, §17.5).
 *
 * A query carrying `#[ProtectedQuery(ability: ..., subjectType: ...)]` is
 * checked against the owning aggregate's policy (`view` / `list` / named
 * ability) before the reader runs. A query without the attribute is a
 * permission-gated reference read (§6.2) — no policy call here.
 */
final class AuthorizeQuery
{
    public function __construct(private readonly Gate $gate) {}

    public function handle(object $query, Closure $next): mixed
    {
        $attribute = (new ReflectionClass($query))->getAttributes(ProtectedQuery::class)[0] ?? null;

        if ($attribute !== null) {
            /** @var ProtectedQuery $protected */
            $protected = $attribute->newInstance();

            $this->gate->authorize(
                $protected->ability,
                [$protected->subjectType ?? $query::class, $query],
            );
        }

        return $next($query);
    }
}
