<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum AuthType: string
{
    case HMAC_SHA256 = 'HMAC_SHA256';
    case BEARER_TOKEN = 'BEARER_TOKEN';
    case BASIC_AUTH = 'BASIC_AUTH';
    case API_KEY = 'API_KEY';
    case NONE = 'NONE';
}
