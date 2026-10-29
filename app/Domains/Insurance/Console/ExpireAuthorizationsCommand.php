<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Console;

use App\Domains\Insurance\Application\Command\ExpireAuthorization;
use App\Domains\Insurance\Domain\Model\Authorization;
use App\Domains\Insurance\Domain\ValueObject\AuthorizationStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The pre-authorization expiry hygiene sweep (21-insurance-and-payer-domain.md
 * §58 / §182; ADR-202 D-B). Flips APPROVED / PARTIALLY_APPROVED authorizations
 * that are past their `valid_to` date to `EXPIRED` so the stored `status` is
 * honest for worklists and queries. Inv 25 enforcement is ALREADY held
 * check-at-use (`Authorization::isEffectiveOn()`) — a missed sweep cannot let an
 * expired authorization grant coverage.
 *
 * It does NOT bypass RLS — it iterates tenants (the non-RLS `shared_tenants`
 * registry) and runs one `TenantContextScope::run($tenantId)` per tenant, the
 * exact rule `OutboxRelay` follows (IC §11.4). Each candidate is expired by
 * dispatching the system-only `ExpireAuthorization` command, whose handler
 * no-ops defensively on an already-resolved / still-within-validity row (so a
 * double sweep is safe).
 *
 * Scheduled hourly (bootstrap/app.php) — the `procurement:expire-contracts` twin.
 */
final class ExpireAuthorizationsCommand extends Command
{
    protected $signature = 'insurance:expire-authorizations {--once : Process one pass and exit}';

    protected $description = 'Flip APPROVED/PARTIALLY_APPROVED pre-authorizations past their valid_to date to EXPIRED (ADR-202 hygiene sweep).';

    public function handle(CommandBus $bus): int
    {
        /** @var list<string> $tenantIds */
        $tenantIds = DB::table('shared_tenants')->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $expired = 0;

        foreach ($tenantIds as $tenantId) {
            /** @var list<string> $authIds */
            $authIds = TenantContextScope::run($tenantId, null, static fn (): array => Authorization::query()
                ->whereIn('status', AuthorizationStatus::EFFECTIVE)
                ->whereDate('valid_to', '<', now()->toDateString())
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->all());

            foreach ($authIds as $authId) {
                try {
                    TenantContextScope::run(
                        $tenantId,
                        null,
                        static fn () => $bus->dispatch(new ExpireAuthorization($authId)),
                    );
                    $expired++;
                } catch (Throwable $e) {
                    // One stuck row must not stop the sweep — log and continue.
                    Log::warning('insurance:expire-authorizations failed for authorization '.$authId, [
                        'authorization_id' => $authId,
                        'tenant_id' => $tenantId,
                        'exception' => $e,
                    ]);
                }
            }
        }

        if ($expired > 0) {
            $this->info(sprintf('Expired %d pre-authorization(s).', $expired));
        }

        return self::SUCCESS;
    }
}
