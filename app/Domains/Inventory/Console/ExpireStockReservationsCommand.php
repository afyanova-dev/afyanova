<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Console;

use App\Domains\Inventory\Application\Command\ExpireStockReservation;
use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The stock-reservation expiry sweep (18-inventory-and-warehousing-domain.md
 * §114, Inv 18; ADR-180 D-H). Releases ACTIVE reservations whose `hold_until`
 * has lapsed so `inv_stock_balances.reserved_qty` — and therefore `available` —
 * stays honest. `ReserveStock` also self-heals on touch (ADR-180 D-E), so a
 * missed sweep is bounded.
 *
 * It does NOT bypass RLS — it iterates tenants (the non-RLS `shared_tenants`
 * registry) and runs one `TenantContextScope::run($tenantId)` per tenant, the
 * exact rule `OutboxRelay` follows (IC §11.4). Each candidate is expired by
 * dispatching the system-only `ExpireStockReservation` command, whose handler
 * no-ops defensively on an already-resolved / not-yet-lapsed row.
 *
 * Scheduled hourly (bootstrap/app.php) — the `bw:reservations:expire` twin.
 */
final class ExpireStockReservationsCommand extends Command
{
    protected $signature = 'inventory:expire-reservations {--once : Process one pass and exit}';

    protected $description = 'Release stock reservations whose hold_until has lapsed (ADR-180 hygiene sweep).';

    public function handle(CommandBus $bus): int
    {
        /** @var list<string> $tenantIds */
        $tenantIds = DB::table('shared_tenants')->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $expired = 0;

        foreach ($tenantIds as $tenantId) {
            /** @var list<string> $reservationIds */
            $reservationIds = TenantContextScope::run($tenantId, null, static fn (): array => StockReservation::query()
                ->where('status', StockReservation::STATUS_ACTIVE)
                ->whereNotNull('hold_until')
                ->where('hold_until', '<', now())
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all());

            foreach ($reservationIds as $reservationId) {
                try {
                    TenantContextScope::run(
                        $tenantId,
                        null,
                        static fn () => $bus->dispatch(new ExpireStockReservation($reservationId)),
                    );
                    $expired++;
                } catch (Throwable $e) {
                    Log::warning('inventory:expire-reservations failed for reservation '.$reservationId, [
                        'reservation_id' => $reservationId,
                        'tenant_id' => $tenantId,
                        'exception' => $e,
                    ]);
                }
            }
        }

        if ($expired > 0) {
            $this->info(sprintf('Released %d lapsed stock reservation(s).', $expired));
        }

        return self::SUCCESS;
    }
}
