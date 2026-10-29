<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Console;

use App\Domains\Insurance\Application\Command\ExpirePayerContract;
use App\Domains\Insurance\Domain\Model\PayerContract;
use App\Domains\Insurance\Domain\ValueObject\PayerContractStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The payer contract expiry hygiene sweep (21-insurance-and-payer-domain.md
 * §49 / §113; ADR-203). Flips ACTIVE contracts that are past their `effective_to`
 * date to `EXPIRED` so the stored `status` is honest for queries and worklists.
 *
 * Iterates tenants via `TenantContextScope::run($tenantId)` (IC §11.4).
 * Each candidate is expired by dispatching the system-only `ExpirePayerContract` command.
 *
 * Scheduled hourly (bootstrap/app.php).
 */
final class ExpirePayerContractsCommand extends Command
{
    protected $signature = 'insurance:expire-contracts {--once : Process one pass and exit}';

    protected $description = 'Flip ACTIVE payer contracts past their effective_to date to EXPIRED (ADR-203 hygiene sweep).';

    public function handle(CommandBus $bus): int
    {
        /** @var list<string> $tenantIds */
        $tenantIds = DB::table('shared_tenants')->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $expired = 0;

        foreach ($tenantIds as $tenantId) {
            /** @var list<string> $contractIds */
            $contractIds = TenantContextScope::run($tenantId, null, static fn (): array => PayerContract::query()
                ->where('status', PayerContractStatus::ACTIVE)
                ->whereNotNull('effective_to')
                ->whereDate('effective_to', '<', now()->toDateString())
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all());

            foreach ($contractIds as $contractId) {
                try {
                    TenantContextScope::run(
                        $tenantId,
                        null,
                        static fn () => $bus->dispatch(new ExpirePayerContract($contractId)),
                    );
                    $expired++;
                } catch (Throwable $e) {
                    Log::warning('insurance:expire-contracts failed for contract '.$contractId, [
                        'contract_id' => $contractId,
                        'tenant_id' => $tenantId,
                        'exception' => $e,
                    ]);
                }
            }
        }

        if ($expired > 0) {
            $this->info(sprintf('Expired %d payer contract(s).', $expired));
        }

        return self::SUCCESS;
    }
}
