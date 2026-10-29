<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\CadreCategory;
use App\Shared\Bus\AuthorizesAgainst;

final readonly class UpdateCadre implements AuthorizesAgainst
{
    public CadreCategory $category;

    public function __construct(
        public string $cadreId,
        public string $name,
        string|CadreCategory $category,
        public ?string $description = null,
        public bool $isActive = true,
    ) {
        $this->category = is_string($category)
            ? CadreCategory::from($category)
            : $category;
    }

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): string
    {
        return $this->cadreId;
    }
}
