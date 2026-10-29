<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\ValueObject;

enum StatutoryReturnStatus: string
{
    case DRAFT = 'DRAFT';
    case GENERATED = 'GENERATED';
    case REVIEWED = 'REVIEWED';
    case SUBMITTED = 'SUBMITTED';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case SUPERSEDED = 'SUPERSEDED';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => in_array($target, [self::GENERATED], true),
            self::GENERATED => in_array($target, [self::REVIEWED, self::DRAFT], true),
            self::REVIEWED => in_array($target, [self::SUBMITTED, self::DRAFT], true),
            self::SUBMITTED => in_array($target, [self::ACKNOWLEDGED, self::SUPERSEDED], true),
            self::ACKNOWLEDGED => in_array($target, [self::SUPERSEDED], true),
            self::SUPERSEDED => false,
        };
    }
}
