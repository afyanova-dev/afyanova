<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts\Dto;

final readonly class StockLocationTypeSummary
{
    public function __construct(
        public string $code,
        public string $name,
        public string $scope,
    ) {}
}
