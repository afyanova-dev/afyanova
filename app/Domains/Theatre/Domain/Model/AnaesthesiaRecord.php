<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Model;

use App\Domains\Theatre\Contracts\Event\AnaesthesiaRecordAmended;
use App\Domains\Theatre\Contracts\Event\AnaesthesiaRecordCreated;
use App\Domains\Theatre\Contracts\Event\AnaesthesiaRecordSigned;
use App\Domains\Theatre\Database\Factories\AnaesthesiaRecordFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * AnaesthesiaRecord aggregate root (17-theatre-domain.md §36–§40, §82, §83;
 * ADR-155 D-B/D-C) — Theatre-owned anaesthesia documentation, one per
 * SurgicalCase. NOT a `ClinicalNote` (§37 — a specialized Theatre capability).
 *
 * Emits AnaesthesiaRecordCreated, AnaesthesiaRecordSigned,
 * AnaesthesiaRecordAmended. Content updates emit NO event (audit-only — the
 * T.2 UpdateOperativeNoteContent precedent). Lifecycle DRAFT → SIGNED →
 * AMENDED (no REVIEWED — §36–§40 names none).
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
 * @property string $anaesthesia_type
 * @property string|null $provider_user_id
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property array<string, mixed> $content
 * @property string|null $outcome
 * @property string $status
 * @property string|null $signed_by_user_id
 * @property CarbonImmutable|null $signed_at
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class AnaesthesiaRecord extends AggregateRoot
{
    /** @use HasFactory<AnaesthesiaRecordFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_SIGNED = 'SIGNED';

    public const STATUS_AMENDED = 'AMENDED';

    /** @var list<string> Statuses in which content may still be replaced (ADR-155 D-C). */
    public const CONTENT_MUTABLE_STATUSES = [self::STATUS_DRAFT];

    /** @var list<string> Statuses from which an amendment may be appended (ADR-155 D-C). */
    public const AMENDABLE_STATUSES = [self::STATUS_SIGNED, self::STATUS_AMENDED];

    protected $table = 'thr_anaesthesia_records';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'case_id',
        'patient_id',
        'encounter_id',
        'anaesthesia_type',
        'provider_user_id',
        'started_at',
        'ended_at',
        'content',
        'outcome',
        'status',
        'signed_by_user_id',
        'signed_at',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): AnaesthesiaRecordFactory
    {
        return AnaesthesiaRecordFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
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
        string $anaesthesiaType,
        ?string $providerUserId,
        ?CarbonImmutable $startedAt,
        ?CarbonImmutable $endedAt,
        array $content,
        ?string $outcome,
        string $createdByUserId,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $occurred = $occurredAt ?? CarbonImmutable::now();
        $recorded = CarbonImmutable::now();

        $record = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'case_id' => $caseId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'anaesthesia_type' => $anaesthesiaType,
            'provider_user_id' => $providerUserId,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'content' => $content,
            'outcome' => $outcome,
            'status' => self::STATUS_DRAFT,
            'signed_by_user_id' => null,
            'signed_at' => null,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurred,
            'recorded_at' => $recorded,
        ]);

        $record->recordThat(new AnaesthesiaRecordCreated(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            anaesthesiaRecordId: $id,
            caseId: $caseId,
            patientId: $patientId,
            encounterId: $encounterId,
            anaesthesiaType: $anaesthesiaType,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $record;
    }

    /**
     * @param  array<string, mixed>|null  $content
     */
    public function updateContent(?array $content, ?CarbonImmutable $startedAt, ?CarbonImmutable $endedAt, ?string $outcome): void
    {
        if ($content !== null) {
            $this->content = $content;
        }

        if ($startedAt !== null) {
            $this->started_at = $startedAt;
        }

        if ($endedAt !== null) {
            $this->ended_at = $endedAt;
        }

        if ($outcome !== null) {
            $this->outcome = $outcome;
        }
    }

    public function sign(string $signerUserId): void
    {
        $this->status = self::STATUS_SIGNED;
        $this->signed_by_user_id = $signerUserId;
        $this->signed_at = CarbonImmutable::now();

        $this->recordThat(new AnaesthesiaRecordSigned(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            anaesthesiaRecordId: (string) $this->id,
            caseId: (string) $this->case_id,
            signedByUserId: $signerUserId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * Flips SIGNED → AMENDED on the first amendment; stays AMENDED thereafter.
     * The amendment ROW is written by the handler (plain child — ADR-155 D-B).
     */
    public function recordAmendment(string $amendmentId, int $sequence): void
    {
        $this->status = self::STATUS_AMENDED;

        $this->recordThat(new AnaesthesiaRecordAmended(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            anaesthesiaRecordId: (string) $this->id,
            caseId: (string) $this->case_id,
            amendmentId: $amendmentId,
            sequence: $sequence,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
