<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

use App\Platform\Documents\Contracts\Enum\DocumentStatus;

class_alias(
    DocumentStatus::class,
    __NAMESPACE__.'\\DocumentStatus',
);
