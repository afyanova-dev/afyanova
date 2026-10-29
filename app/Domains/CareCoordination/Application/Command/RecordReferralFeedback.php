<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Record feedback content on a referral's feedback thread
 * (26-care-coordination-domain.md §6, §9; ADR-151 D-D/D-F). `kind` is
 * open-coded (ACKNOWLEDGEMENT|INTERIM|FINAL — not strictly validated).
 * `summary`/`outcome` are row-only (ADR-136 deny-list), never carried on
 * `ReferralFeedbackRecorded`. Re-entrant while PARTIAL (content may be
 * updated before `CompleteReferralFeedback` finalizes the thread).
 */
final readonly class RecordReferralFeedback implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $feedbackId,
        public string $actorId,
        public string $kind,
        public ?string $summary = null,
        public ?string $outcome = null,
        public ?string $receivedVia = null,
        public ?string $receivedAt = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($feedbackId)) {
            throw new InvalidArgumentException('RecordReferralFeedback::$feedbackId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RecordReferralFeedback::$actorId must be a UUID (IC §3).');
        }

        if (trim($kind) === '') {
            throw new InvalidArgumentException('RecordReferralFeedback::$kind must not be blank.');
        }

        if ($receivedAt !== null) {
            try {
                CarbonImmutable::parse($receivedAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordReferralFeedback::$receivedAt must be an ISO-8601 datetime or null.');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): string
    {
        return $this->feedbackId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'care_coordination.referral_feedback_recorded',
            actorId: $this->actorId,
            targetType: 'referral_feedback',
            targetId: $this->feedbackId,
            metadata: [
                'kind' => $this->kind,
            ],
        );
    }
}
