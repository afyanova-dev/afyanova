<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\Model\StatutoryReturn;
use Illuminate\Database\Eloquent\Collection;

final class ListStatutoryReturnsHandler
{
    /**
     * @return Collection<int, StatutoryReturn>
     */
    public function __invoke(ListStatutoryReturnsQuery $query): Collection
    {
        return StatutoryReturn::query()
            ->where('tenant_id', $query->tenantId)
            ->when($query->framework !== null, fn ($q) => $q->where('framework', $query->framework))
            ->when($query->periodKey !== null, fn ($q) => $q->where('period_key', $query->periodKey))
            ->orderByDesc('created_at')
            ->get();
    }
}
