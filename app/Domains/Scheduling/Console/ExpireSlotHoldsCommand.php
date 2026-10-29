<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Console;

use App\Domains\Scheduling\Application\Command\ExpireSlotHold;
use App\Domains\Scheduling\Domain\Model\Slot;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sweeps lapsed slot holds and releases them back to OPEN
 * (22-scheduling-and-appointments-domain.md §45).
 */
final class ExpireSlotHoldsCommand extends Command
{
    protected $signature = 'scheduling:expire-slot-holds {--once : Process one pass and exit}';

    protected $description = 'Sweep and release lapsed slot holds back to OPEN.';

    public function handle(CommandBus $bus): int
    {
        /** @var list<string> $tenantIds */
        $tenantIds = DB::table('shared_tenants')->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $expired = 0;

        foreach ($tenantIds as $tenantId) {
            /** @var list<string> $slotIds */
            $slotIds = TenantContextScope::run($tenantId, null, static fn (): array => Slot::query()
                ->where('status', 'HELD')
                ->where('held_until', '<', now())
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all());

            foreach ($slotIds as $slotId) {
                try {
                    TenantContextScope::run(
                        $tenantId,
                        null,
                        static fn () => $bus->dispatch(new ExpireSlotHold($slotId)),
                    );
                    $expired++;
                } catch (Throwable $e) {
                    Log::error("Failed to expire slot hold {$slotId}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Expired {$expired} lapsed slot holds.");

        return self::SUCCESS;
    }
}
