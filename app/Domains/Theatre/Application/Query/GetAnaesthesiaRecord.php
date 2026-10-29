<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one anaesthesia record, including its amendment history (ADR-155 D-F).
 */
#[ProtectedQuery(ability: 'view', subjectType: AnaesthesiaRecord::class)]
final readonly class GetAnaesthesiaRecord
{
    public function __construct(public string $anaesthesiaRecordId)
    {
        if (! Str::isUuid($anaesthesiaRecordId)) {
            throw new InvalidArgumentException('GetAnaesthesiaRecord::$anaesthesiaRecordId must be a UUID (IC §3).');
        }
    }
}
