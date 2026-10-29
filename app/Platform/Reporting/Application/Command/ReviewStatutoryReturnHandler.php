<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Application\Command;

use App\Platform\Reporting\Domain\Exception\StatutoryReturnNotFound;
use App\Platform\Reporting\Domain\Model\StatutoryReturn;
use App\Platform\Reporting\Domain\ValueObject\StatutoryReturnStatus;
use Carbon\CarbonImmutable;

final class ReviewStatutoryReturnHandler
{
    public function __invoke(ReviewStatutoryReturnCommand $command): StatutoryReturn
    {
        /** @var StatutoryReturn|null $return */
        $return = StatutoryReturn::query()
            ->where('tenant_id', $command->tenantId)
            ->where('id', $command->returnId)
            ->first();

        if ($return === null) {
            throw new StatutoryReturnNotFound("Statutory return '{$command->returnId}' not found.");
        }

        $return->transitionTo(StatutoryReturnStatus::REVIEWED);
        $return->reviewed_by = $command->reviewedBy;
        $return->reviewed_at = CarbonImmutable::now();
        $return->save();

        return $return;
    }
}
