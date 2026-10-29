<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Console;

use App\Domains\Scheduling\Application\Command\ExpireLapsedWaitlistOffers;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sweeps lapsed waitlist offers and releases held slots
 * (22-scheduling-and-appointments-domain.md §27).
 */
final class ExpireWaitlistOffersCommand extends Command
{
    protected $signature = 'scheduling:expire-waitlist-offers {--once : Process one pass and exit}';

    protected $description = 'Sweep lapsed waitlist offers and release held slots back to availability.';

    public function handle(CommandBus $bus): int
    {
        /** @var list<string> $tenantIds */
        $tenantIds = DB::table('shared_tenants')->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $totalExpired = 0;

        foreach ($tenantIds as $tenantId) {
            try {
                $count = TenantContextScope::run(
                    $tenantId,
                    null,
                    static fn (): int => (int) $bus->dispatch(new ExpireLapsedWaitlistOffers),
                );
                $totalExpired += $count;
            } catch (Throwable $e) {
                Log::error("Failed to expire waitlist offers for tenant {$tenantId}: {$e->getMessage()}");
            }
        }

        $this->info("Expired {$totalExpired} lapsed waitlist offers.");

        return self::SUCCESS;
    }
}
