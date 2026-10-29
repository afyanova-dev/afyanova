<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Query;

use App\Platform\Reporting\Domain\Model\StatutoryReturn;

final class GetStatutoryReturnHandler
{
    public function __invoke(GetStatutoryReturnQuery $query): ?StatutoryReturn
    {
        return StatutoryReturn::query()
            ->where('tenant_id', $query->tenantId)
            ->where(function ($q) use ($query): void {
                $q->where('id', $query->returnId)
                    ->orWhere('return_number', $query->returnId);
            })
            ->with('lines')
            ->first();
    }
}
