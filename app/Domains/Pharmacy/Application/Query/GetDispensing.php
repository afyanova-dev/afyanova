<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one Dispensing record (16-pharmacy-domain.md §24, §127, §255;
 * ADR-184 D-I). `view` gates on the coarse `pharmacy.access` (the
 * `GetPharmacyOrder` precedent — RLS isolates the tenant).
 */
#[ProtectedQuery(ability: 'view', subjectType: PharmacyOrder::class)]
final readonly class GetDispensing
{
    public function __construct(public string $dispensingId)
    {
        if (! Str::isUuid($dispensingId)) {
            throw new InvalidArgumentException('GetDispensing::$dispensingId must be a UUID (IC §3).');
        }
    }
}
