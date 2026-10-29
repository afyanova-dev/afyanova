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
 * Open a care episode as OPEN (26-care-coordination-domain.md §6, §9;
 * ADR-153 D-E). `$episodeId` is client-minted (UUIDv7 — the CreateReferral
 * precedent). `facility_id` is the caller's active facility context (ADR-132
 * originating convention), never from this command.
 *
 * `$type` is open-coded, normalised uppercase by the handler. Invariant 7.4
 * (one OPEN episode per `(type, subtype)` per patient) is DB-enforced; an
 * explicit override is `$allowConcurrent` = true + a non-blank
 * `$concurrentReason` (ADR-153 D-J — the ADR-117 override precedent).
 */
final readonly class OpenCareEpisode implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, a backdated open must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $episodeId,
        public string $patientId,
        public string $actorId,
        public string $type,
        public ?string $subtype = null,
        public bool $allowConcurrent = false,
        public ?string $concurrentReason = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($episodeId)) {
            throw new InvalidArgumentException('OpenCareEpisode::$episodeId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('OpenCareEpisode::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('OpenCareEpisode::$actorId must be a UUID (IC §3).');
        }

        if (trim($type) === '') {
            throw new InvalidArgumentException('OpenCareEpisode::$type must not be blank.');
        }

        if ($subtype !== null && trim($subtype) === '') {
            throw new InvalidArgumentException('OpenCareEpisode::$subtype must be a non-blank string or null.');
        }

        if ($allowConcurrent && ($concurrentReason === null || trim($concurrentReason) === '')) {
            throw new InvalidArgumentException('OpenCareEpisode::$concurrentReason is required when $allowConcurrent is true.');
        }

        if (! $allowConcurrent && $concurrentReason !== null) {
            throw new InvalidArgumentException('OpenCareEpisode::$concurrentReason must be null unless $allowConcurrent is true.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('OpenCareEpisode::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('OpenCareEpisode::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'OpenCareEpisode::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        $reason = $this->allowConcurrent
            ? trim((string) $this->concurrentReason)
            : (($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null);

        return new AuditEntry(
            action: 'care_coordination.episode_opened',
            actorId: $this->actorId,
            targetType: 'care_episode',
            targetId: $this->episodeId,
            reason: $reason,
            metadata: [
                'patient_id' => $this->patientId,
                'type' => strtoupper(trim($this->type)),
                'override' => $this->allowConcurrent,
            ],
        );
    }
}
