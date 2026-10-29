<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Console;

use App\Domains\BedWard\Application\Command\ExpireBedReservation;
use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The deferred B.2 reservation-expiry hygiene job (ADR-149 D-C — "a periodic
 * hygiene command … sweeping RESERVED beds with a lapsed hold_until, for the
 * case where nothing ever touches the bed again to trigger the self-healing
 * check"). ADR-149 already decided this; this class is the implementation.
 *
 * It does NOT bypass RLS — it iterates tenants (the non-RLS `shared_tenants`
 * registry) and runs one `TenantContextScope::run($tenantId)` per tenant, the
 * exact rule `OutboxRelay` follows (IC §11.4). Each lapsed reservation is
 * expired by dispatching the system-only `ExpireBedReservation` command, whose
 * handler already no-ops defensively on an already-resolved row (so a double
 * sweep, or a race with `AssignBed`'s own self-heal, is safe).
 *
 * Scheduled hourly (bootstrap/app.php) — a coarser cadence than
 * `outbox:relay`'s every-minute, per ADR-149 D-C.
 */
final class ExpireLapsedBedReservationsCommand extends Command
{
    protected $signature = 'bw:reservations:expire {--once : Process one pass and exit}';

    protected $description = 'Expire bed reservations whose hold_until has lapsed (ADR-149 D-C hygiene job).';

    public function handle(CommandBus $bus): int
    {
        /** @var list<string> $tenantIds */
        $tenantIds = DB::table('shared_tenants')->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $expired = 0;

        foreach ($tenantIds as $tenantId) {
            /** @var list<string> $reservationIds */
            $reservationIds = TenantContextScope::run($tenantId, null, static fn (): array => BedReservation::query()
                ->whereIn('status', [BedReservation::STATUS_REQUESTED, BedReservation::STATUS_HELD])
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
                        static fn () => $bus->dispatch(new ExpireBedReservation($reservationId)),
                    );
                    $expired++;
                } catch (Throwable $e) {
                    // One stuck row must not stop the sweep — log and continue.
                    Log::warning('bw:reservations:expire failed for reservation '.$reservationId, [
                        'reservation_id' => $reservationId,
                        'tenant_id' => $tenantId,
                        'exception' => $e,
                    ]);
                }
            }
        }

        if ($expired > 0) {
            $this->info(sprintf('Expired %d lapsed bed reservation(s).', $expired));
        }

        return self::SUCCESS;
    }
}
