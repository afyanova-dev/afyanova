<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Model;

use App\Domains\Theatre\Contracts\Event\OperativeNoteAmended;
use App\Domains\Theatre\Contracts\Event\OperativeNoteCreated;
use App\Domains\Theatre\Contracts\Event\OperativeNoteReviewed;
use App\Domains\Theatre\Contracts\Event\OperativeNoteSigned;
use App\Domains\Theatre\Database\Factories\OperativeNoteFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * OperativeNote aggregate root — theatre-specific surgical documentation
 * (17-theatre-domain.md §77–§83, §216; ADR-152 D-C/D-D).
 *
 * NOT a `ClinicalNote` — the REVIEWED lifecycle stage (§79) has no place in
 * ClinicalNoteType (ADR-150's own rejected alternative, ratified by
 * ADR-152 D-K). One per SurgicalCase.
 *
 * Emits OperativeNoteCreated, OperativeNoteReviewed, OperativeNoteSigned,
 * OperativeNoteAmended. Content updates emit NO event (audit-only — the T.1
 * CompleteChecklistStage precedent).
 *
 * @domain Theatre
 *
 * @authority authoritative
 *
 * @tenant required
 *
 * @facility required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $case_id
 * @property string $patient_id
 * @property string $encounter_id
 * @property array<string, mixed> $content
 * @property string $status
 * @property string|null $reviewed_by_user_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $signed_by_user_id
 * @property CarbonImmutable|null $signed_at
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class OperativeNote extends AggregateRoot
{
    /** @use HasFactory<OperativeNoteFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_REVIEWED = 'REVIEWED';

    public const STATUS_SIGNED = 'SIGNED';

    public const STATUS_AMENDED = 'AMENDED';

    /** @var list<string> Statuses in which content may still be replaced (ADR-152 D-D). */
    public const CONTENT_MUTABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REVIEWED];

    /** @var list<string> Statuses from which an amendment may be appended (ADR-152 D-D). */
    public const AMENDABLE_STATUSES = [self::STATUS_SIGNED, self::STATUS_AMENDED];

    protected $table = 'thr_operative_notes';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'case_id',
        'patient_id',
        'encounter_id',
        'content',
        'status',
        'reviewed_by_user_id',
        'reviewed_at',
        'signed_by_user_id',
        'signed_at',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): OperativeNoteFactory
    {
        return OperativeNoteFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'reviewed_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $caseId,
        string $patientId,
        string $encounterId,
        array $content,
        string $createdByUserId,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $occurred = $occurredAt ?? CarbonImmutable::now();
        $recorded = CarbonImmutable::now();

        $note = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'case_id' => $caseId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'content' => $content,
            'status' => self::STATUS_DRAFT,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'signed_by_user_id' => null,
            'signed_at' => null,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurred,
            'recorded_at' => $recorded,
        ]);

        $now = CarbonImmutable::now()->toIso8601String();

        $note->recordThat(new OperativeNoteCreated(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            operativeNoteId: $id,
            caseId: $caseId,
            patientId: $patientId,
            encounterId: $encounterId,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $note;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function updateContent(array $content): void
    {
        $this->content = $content;
    }

    public function review(string $reviewerUserId): void
    {
        $this->status = self::STATUS_REVIEWED;
        $this->reviewed_by_user_id = $reviewerUserId;
        $this->reviewed_at = CarbonImmutable::now();

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new OperativeNoteReviewed(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            operativeNoteId: (string) $this->id,
            caseId: (string) $this->case_id,
            reviewedByUserId: $reviewerUserId,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function sign(string $signerUserId): void
    {
        $this->status = self::STATUS_SIGNED;
        $this->signed_by_user_id = $signerUserId;
        $this->signed_at = CarbonImmutable::now();

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new OperativeNoteSigned(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            operativeNoteId: (string) $this->id,
            caseId: (string) $this->case_id,
            signedByUserId: $signerUserId,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * Flips SIGNED → AMENDED on the first amendment; stays AMENDED
     * thereafter. The amendment ROW itself is written by the handler (plain
     * child table — ADR-152 D-C).
     */
    public function recordAmendment(string $amendmentId, int $sequence): void
    {
        $this->status = self::STATUS_AMENDED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new OperativeNoteAmended(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            operativeNoteId: (string) $this->id,
            caseId: (string) $this->case_id,
            amendmentId: $amendmentId,
            sequence: $sequence,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
