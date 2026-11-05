<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

use App\Platform\Documents\Contracts\Enum\ConfidentialityLevel;

class_alias(
    ConfidentialityLevel::class,
    __NAMESPACE__.'\\ConfidentialityLevel',
);
