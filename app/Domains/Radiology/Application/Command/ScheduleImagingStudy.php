<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Schedule an imaging study against one ordered service (15-radiology-domain.md
 * §19, §23–§27, §104–§106; ADR-166 D-F). Creates the study at `SCHEDULED` and
 * allocates its `RADAC-%08d` accession.
 *
 * `$studyId` is client-minted (UUIDv7). `$appointmentRef` / `$equipmentRef` are
 * OPAQUE strings the caller supplies — Scheduling owns the appointment record
 * (`blueprint §13.2`), asset management owns the equipment (§26). `$scheduledFor`
 * is an informational ISO-8601 slot time. `$repeatOfStudyId` links a
 * within-request repeat (§60).
 */
final readonly class ScheduleImagingStudy implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $studyId,
        public string $orderId,
        public string $orderItemId,
        public string $actorId,
        public ?string $scheduledFor = null,
        public ?string $appointmentRef = null,
        public ?string $equipmentRef = null,
        public ?string $repeatOfStudyId = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['studyId' => $studyId, 'orderId' => $orderId, 'orderItemId' => $orderItemId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ScheduleImagingStudy::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($repeatOfStudyId !== null && ! Str::isUuid($repeatOfStudyId)) {
            throw new InvalidArgumentException('ScheduleImagingStudy::$repeatOfStudyId must be a UUID or null (IC §3).');
        }

        foreach (['appointmentRef' => $appointmentRef, 'equipmentRef' => $equipmentRef, 'scheduledFor' => $scheduledFor] as $name => $value) {
            if ($value !== null && trim($value) === '') {
                throw new InvalidArgumentException("ScheduleImagingStudy::\${$name} must be a non-blank string or null.");
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
        return new AuditEntry(
            action: 'radiology.study_scheduled',
            actorId: $this->actorId,
            targetType: 'imaging_study',
            targetId: $this->studyId,
            metadata: [
                'order_id' => $this->orderId,
                'order_item_id' => $this->orderItemId,
            ],
        );
    }
}
