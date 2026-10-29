<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Edit a DRAFT / REVIEWED radiology report's prose sections
 * (15-radiology-domain.md §40–§41, §162; ADR-167 D-D). A named section absent
 * from the payload is left unchanged; an explicit `null` clears it. No domain
 * event. Optimistic concurrency is the aggregate `version` (§163).
 */
final readonly class UpdateRadiologyReport implements Auditable, AuthorizesAgainst
{
    /**
     * @param  array<string, string|null>  $sections  keys: clinicalIndicationText, techniqueText, findingsText, impressionText, recommendationsText
     */
    public function __construct(
        public string $reportId,
        public string $actorId,
        public array $sections,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($reportId)) {
            throw new InvalidArgumentException('UpdateRadiologyReport::$reportId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdateRadiologyReport::$actorId must be a UUID (IC §3).');
        }

        $allowed = ['clinicalIndicationText', 'techniqueText', 'findingsText', 'impressionText', 'recommendationsText'];
        foreach (array_keys($sections) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("UpdateRadiologyReport::\$sections has an unknown key [{$key}].");
            }
        }

        if ($sections === []) {
            throw new InvalidArgumentException('UpdateRadiologyReport::$sections must name at least one section.');
        }
    }

    public function policyAbility(): string
    {
        return 'author';
    }

    public function subjectId(): string
    {
        return $this->reportId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'radiology.report_updated',
            actorId: $this->actorId,
            targetType: 'radiology_report',
            targetId: $this->reportId,
            metadata: ['sections' => array_keys($this->sections)],
        );
    }
}
