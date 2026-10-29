<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum CredentialAlgorithm: string
{
    case HMAC_SHA256 = 'HMAC_SHA256';
    case RSA_2048 = 'RSA_2048';
    case ED25519 = 'ED25519';
    case RAW_TOKEN = 'RAW_TOKEN';
}
