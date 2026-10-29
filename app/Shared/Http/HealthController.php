<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Shared\Support\CorrelationId;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Foundation landing / health screen. The bare `/up` endpoint
 * (bootstrap/app.php) proves the app boots; this proves the dependencies
 * the contract needs are reachable (IMPLEMENTATION-CONTRACTS.md §19).
 */
final class HealthController
{
    public function __invoke(): Response
    {
        return Inertia::render('System/Health', [
            'checks' => [
                'postgres' => $this->check(fn () => DB::connection()->select('select 1') !== []),
                'redis' => $this->check(fn () => (string) Redis::connection()->ping() !== ''),
                'queue' => $this->check(fn () => config('queue.default') === 'redis'),
            ],
            'correlationId' => CorrelationId::get(),
        ]);
    }

    private function check(callable $probe): string
    {
        try {
            return $probe() ? 'ok' : 'fail';
        } catch (\Throwable) {
            return 'fail';
        }
    }
}
