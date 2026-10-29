<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\ItemSummary;
use App\Domains\Inventory\Domain\ItemReader;

final class GetItemsHandler
{
    public function __construct(private readonly ItemReader $reader) {}

    /**
     * @return list<ItemSummary>
     */
    public function __invoke(GetItems $query): array
    {
        return $this->reader->list($query->itemType, $query->active);
    }
}
