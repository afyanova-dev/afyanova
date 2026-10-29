<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Enum;

enum DocumentType: string
{
    case INVOICE = 'INVOICE';
    case RECEIPT = 'RECEIPT';
    case LAB_REPORT = 'LAB_REPORT';
    case IMAGING_REPORT = 'IMAGING_REPORT';
    case DISCHARGE_SUMMARY = 'DISCHARGE_SUMMARY';
    case PRESCRIPTION = 'PRESCRIPTION';
    case REFERRAL_LETTER = 'REFERRAL_LETTER';
    case CONSENT = 'CONSENT';
    case ID_SCAN = 'ID_SCAN';
    case PATIENT_PHOTO = 'PATIENT_PHOTO';
    case STAFF_PHOTO = 'STAFF_PHOTO';
    case CLAIM_ATTACHMENT = 'CLAIM_ATTACHMENT';
    case OTHER = 'OTHER';
}
