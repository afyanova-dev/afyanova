<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\Position;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: Position::class)]
final readonly class GetPositions
{
    public function __construct(
        public ?string $cadreId = null,
        public ?bool $activeOnly = true,
    ) {}
}
