<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Query;

use App\Domains\Inventory\Contracts\Dto\ItemSummary;
use App\Domains\Inventory\Domain\ItemReader;

final class GetItemHandler
{
    public function __construct(private readonly ItemReader $reader) {}

    public function __invoke(GetItem $query): ItemSummary
    {
        return $this->reader->summary($query->itemId);
    }
}
