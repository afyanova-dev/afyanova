<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

use App\Platform\Documents\Contracts\Enum\DocumentKind;

class_alias(
    DocumentKind::class,
    __NAMESPACE__.'\\DocumentKind',
);
