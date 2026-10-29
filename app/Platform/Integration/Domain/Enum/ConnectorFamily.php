<?php

declare(strict_types=1);

namespace App\Platform\Integration\Domain\Enum;

enum ConnectorFamily: string
{
    case PAYMENT_GATEWAY = 'PAYMENT_GATEWAY';
    case INSURANCE_PAYER = 'INSURANCE_PAYER';
    case MESSAGING_GATEWAY = 'MESSAGING_GATEWAY';
    case DIAGNOSTIC_ANALYZER = 'DIAGNOSTIC_ANALYZER';
    case NATIONAL_REGISTRY = 'NATIONAL_REGISTRY';
    case ACCOUNTING_ERP = 'ACCOUNTING_ERP';
    case OTHER = 'OTHER';
}
