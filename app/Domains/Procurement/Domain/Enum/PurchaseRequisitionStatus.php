<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Enum;

enum PurchaseRequisitionStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Cancelled = 'CANCELLED';
}
