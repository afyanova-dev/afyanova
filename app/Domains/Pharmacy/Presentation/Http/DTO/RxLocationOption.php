<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One stock location option for the dispense picker.
 */
#[TypeScript]
final readonly class RxLocationOption
{
    public function __construct(
        public string $locationId,
        public string $code,
        public string $name,
    ) {}
}
