<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Exception;

use DomainException;

final class ConsentRequiredException extends DomainException
{
    public static function forTemplate(string $key): self
    {
        return new self("Template [{$key}] requires statutory communication consent, but no valid consent basis was provided.");
    }
}
