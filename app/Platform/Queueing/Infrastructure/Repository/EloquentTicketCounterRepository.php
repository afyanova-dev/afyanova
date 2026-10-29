<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Infrastructure\Repository;

use App\Platform\Queueing\Domain\Repository\TicketCounterRepository;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EloquentTicketCounterRepository implements TicketCounterRepository
{
    public function __construct(private readonly Container $container) {}

    private function tenantContext(): TenantContext
    {
        return $this->container->make(TenantContext::class);
    }

    public function nextNumber(string $facilityId, string $queueCode, string $counterDate): int
    {
        $tenantId = $this->tenantContext()->tenantId();

        DB::table('qms_ticket_counters')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'queue_code' => $queueCode,
            'counter_date' => $counterDate,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var object{allocated: int|string}|null $row */
        $row = DB::selectOne(
            'UPDATE qms_ticket_counters
                SET last_number = last_number + 1, updated_at = now()
              WHERE tenant_id = ? AND facility_id = ? AND queue_code = ? AND counter_date = ?
          RETURNING last_number AS allocated',
            [$tenantId, $facilityId, $queueCode, $counterDate],
        );

        if ($row === null) {
            throw new RuntimeException("Failed to allocate ticket counter for {$queueCode} on {$counterDate}.");
        }

        return (int) $row->allocated;
    }
}
