<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Gender classification for persons in AfyaNova.
 */
enum Gender: string
{
    case MALE = 'MALE';
    case FEMALE = 'FEMALE';
    case OTHER = 'OTHER';
    case UNKNOWN = 'UNKNOWN';
}
