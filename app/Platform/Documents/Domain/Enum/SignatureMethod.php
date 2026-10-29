<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

enum SignatureMethod: string
{
    case DRAWN_VECTOR = 'DRAWN_VECTOR';
    case CRYPTO_SEAL = 'CRYPTO_SEAL';
    case OTP_ATTESTATION = 'OTP_ATTESTATION';
    case PKI_CERTIFICATE = 'PKI_CERTIFICATE';
}
