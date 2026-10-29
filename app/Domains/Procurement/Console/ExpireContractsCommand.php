<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Console;

use App\Domains\Procurement\Application\Command\ExpireContract;
use App\Domains\Procurement\Domain\Model\Contract;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The contract-expiry hygiene sweep (19-procurement-domain.md §194; ADR-179
 * D-E). Flips ACTIVE contracts that are past their `expiry_date` to `EXPIRED`
 * so the stored `status` is honest for `SearchContracts`. Inv 32 enforcement is
 * ALREADY held check-at-use (`Contract::isUsable()` + `ContractNotUsable`) — a
 * missed sweep cannot let an expired contract price a PO.
 *
 * It does NOT bypass RLS — it iterates tenants (the non-RLS `shared_tenants`
 * registry) and runs one `TenantContextScope::run($tenantId)` per tenant, the
 * exact rule `OutboxRelay` follows (IC §11.4). Each candidate is expired by
 * dispatching the system-only `ExpireContract` command, whose handler no-ops
 * defensively on an already-resolved / still-within-term row (so a double sweep,
 * or a race with `RenewContract` / `TerminateContract`, is safe).
 *
 * Scheduled hourly (bootstrap/app.php) — the `bw:reservations:expire` twin.
 */
final class ExpireContractsCommand extends Command
{
    protected $signature = 'procurement:expire-contracts {--once : Process one pass and exit}';

    protected $description = 'Flip ACTIVE purchase contracts past their expiry_date to EXPIRED (ADR-179 hygiene sweep).';

    public function handle(CommandBus $bus): int
    {
        /** @var list<string> $tenantIds */
        $tenantIds = DB::table('shared_tenants')->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $expired = 0;

        foreach ($tenantIds as $tenantId) {
            /** @var list<string> $contractIds */
            $contractIds = TenantContextScope::run($tenantId, null, static fn (): array => Contract::query()
                ->where('status', Contract::STATUS_ACTIVE)
                ->whereDate('expiry_date', '<', now())
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all());

            foreach ($contractIds as $contractId) {
                try {
                    TenantContextScope::run(
                        $tenantId,
                        null,
                        static fn () => $bus->dispatch(new ExpireContract($contractId)),
                    );
                    $expired++;
                } catch (Throwable $e) {
                    // One stuck row must not stop the sweep — log and continue.
                    Log::warning('procurement:expire-contracts failed for contract '.$contractId, [
                        'contract_id' => $contractId,
                        'tenant_id' => $tenantId,
                        'exception' => $e,
                    ]);
                }
            }
        }

        if ($expired > 0) {
            $this->info(sprintf('Expired %d contract(s).', $expired));
        }

        return self::SUCCESS;
    }
}
