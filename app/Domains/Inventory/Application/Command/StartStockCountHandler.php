<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\Exception\StockCountNotAllowed;
use App\Domains\Inventory\Domain\Model\StockCount;
use App\Domains\Inventory\Domain\Model\StockCountLine;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Inventory\Domain\StockBalanceRepository;
use App\Domains\Inventory\Domain\StockCountRepository;
use App\Domains\Inventory\Infrastructure\Sequence\CountNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Orchestrates `StartStockCount` (Phase 8, stock counts).
 *
 * Flow: resolve the active location (→ facility) → ADR-133 per-facility
 * refinement (`inventory.counts.manage`) → refuse when a count is already open
 * at that location → allocate `CNT-%08d` → save the count → snapshot every
 * non-zero balance at the location into the sheet's lines. The snapshot is the
 * fixed reference the counted quantities are compared with.
 *
 * `StockCountRepository` is the first repository parameter so the bus resolves
 * `StockCountPolicy` from it.
 */
final class StartStockCountHandler
{
    public function __construct(
        private readonly StockCountRepository $counts,
        private readonly StockBalanceRepository $balances,
        private readonly StockContextResolver $resolver,
        private readonly CountNumberSequence $countNumbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(StartStockCount $command): void
    {
        $location = $this->resolver->resolveActiveLocation($command->locationId);

        $this->resolver->assertActorScopedToFacility(
            $location->facilityId,
            'inventory.counts.manage',
            $command->stockCountId,
        );

        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();

        $alreadyOpen = StockCount::query()
            ->where('location_id', $location->locationId)
            ->where('status', StockCount::STATUS_IN_PROGRESS)
            ->exists();

        if ($alreadyOpen) {
            throw StockCountNotAllowed::alreadyInProgress($location->locationId);
        }

        $count = StockCount::open(
            id: $command->stockCountId,
            tenantId: $tenantId,
            facilityId: $location->facilityId,
            countNumber: $this->countNumbers->next($tenantId),
            locationId: $location->locationId,
            notes: $command->notes,
            startedByUserId: $command->actorId,
            startedAt: $now,
        );

        try {
            $this->counts->save($count);
        } catch (QueryException $exception) {
            // Two starts at the same location raced past the check above; the
            // partial unique index refused the second one. Report the same
            // friendly rule rather than a database error.
            if (($exception->errorInfo[0] ?? null) === '23505') {
                throw StockCountNotAllowed::alreadyInProgress($location->locationId);
            }

            throw $exception;
        }

        $rows = [];
        foreach ($this->balances->listAtLocation($location->locationId) as $balance) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $location->facilityId,
                'stock_count_id' => $command->stockCountId,
                'item_id' => (string) $balance->item_id,
                'batch_id' => $balance->batch_id !== null ? (string) $balance->batch_id : null,
                'system_qty' => Quantity::of((string) $balance->on_hand_qty),
                'counted_qty' => null,
                'note' => null,
                'counted_by_user_id' => null,
                'counted_at' => null,
                'version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            StockCountLine::query()->insert($rows);
        }
    }
}
