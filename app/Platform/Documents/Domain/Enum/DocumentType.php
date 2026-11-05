<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

use App\Platform\Documents\Contracts\Enum\DocumentType;

class_alias(
    DocumentType::class,
    __NAMESPACE__.'\\DocumentType',
);
