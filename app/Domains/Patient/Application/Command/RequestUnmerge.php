<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reverse the identity-layer merge of a MERGED patient — executed immediately
 * (10-patient-domain.md §25, §130; ADR-118, ADR-128 M-3). It restores the
 * non-surviving patient's exact pre-merge status, flips the `ACTIVE`
 * identity-history row to `REVERSED`, emits `PatientUnmerged` and writes audit
 * — one transaction. No pending-request / approval workflow.
 *
 * `$reason` is mandatory (§104 — high-risk identity changes are audited with a
 * reason); it goes into the audit metadata only (ADR-128 keeps one `reason`
 * column — the original merge reason — on the history row).
 * Authorization: `patient.patients.merge` (the canonical merge permission).
 */
final readonly class RequestUnmerge implements AuthorizesAgainst
{
    public function __construct(
        public string $nonSurvivingPatientId,
        public string $actorId,
        public string $reason,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($nonSurvivingPatientId)) {
            throw new InvalidArgumentException('RequestUnmerge::$nonSurvivingPatientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RequestUnmerge::$actorId must be a UUID (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('RequestUnmerge::$reason is mandatory (§104).');
        }
    }

    public function policyAbility(): string
    {
        return 'merge';
    }

    public function subjectId(): string
    {
        return $this->nonSurvivingPatientId; // per-subject — the bus loads the MERGED patient (IC §5.1)
    }
}
