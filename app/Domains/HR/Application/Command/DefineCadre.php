<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\CadreCategory;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

final readonly class DefineCadre implements AuthorizesAgainst
{
    public string $cadreId;

    public CadreCategory $category;

    public function __construct(
        public string $code,
        public string $name,
        string|CadreCategory $category,
        ?string $cadreId = null,
        public ?string $description = null,
        public bool $isActive = true,
    ) {
        $this->cadreId = $cadreId ?? (string) Str::uuid();
        $this->category = is_string($category)
            ? CadreCategory::from($category)
            : $category;
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
