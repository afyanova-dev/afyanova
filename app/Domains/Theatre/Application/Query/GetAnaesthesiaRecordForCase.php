<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read the anaesthesia record for a surgical case, or null if none exists yet
 * (ADR-155 D-F).
 */
#[ProtectedQuery(ability: 'view', subjectType: AnaesthesiaRecord::class)]
final readonly class GetAnaesthesiaRecordForCase
{
    public function __construct(public string $caseId)
    {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('GetAnaesthesiaRecordForCase::$caseId must be a UUID (IC §3).');
        }
    }
}
