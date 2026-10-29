<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Model;

use App\Domains\Theatre\Contracts\Event\SurgicalSpecimenCollected;
use App\Domains\Theatre\Contracts\Event\SurgicalSpecimenHandedOff;
use App\Domains\Theatre\Database\Factories\SurgicalSpecimenFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * SurgicalSpecimen aggregate root (17-theatre-domain.md §72–§74, §176–§177,
 * §237; ADR-164 D-A) — one specimen removed during a surgical case, tracked
 * through the §74 chain of custody. Multi-per-case.
 *
 * Emits SurgicalSpecimenCollected, SurgicalSpecimenHandedOff (PHI-safe —
 * description NEVER carried). A small AggregateRoot so `save()` drains events
 * (the `RecoveryHandoff` / `thr_case_events` precedent).
 *
 * Theatre retains its own identity; `lab_accession_ref` is filled in LATER by
 * `MarkSurgicalSpecimenReceived` (§177) — no cross-domain call.
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
 * @property int $sequence
 * @property string $specimen_type
 * @property string|null $body_site
 * @property string|null $laterality
 * @property string|null $description
 * @property string $collected_by_user_id
 * @property CarbonImmutable|null $collected_at
 * @property string|null $destination_laboratory
 * @property string|null $lab_accession_ref
 * @property string $status
 * @property string|null $handed_over_by_user_id
 * @property CarbonImmutable|null $handed_over_at
 * @property CarbonImmutable|null $received_recorded_at
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class SurgicalSpecimen extends AggregateRoot
{
    /** @use HasFactory<SurgicalSpecimenFactory> */
    use HasFactory;

    public const STATUS_COLLECTED = 'COLLECTED';

    public const STATUS_LABELED = 'LABELED';

    public const STATUS_HANDED_OVER = 'HANDED_OVER';

    public const STATUS_RECEIVED = 'RECEIVED';

    protected $table = 'thr_surgical_specimens';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'case_id',
        'patient_id',
        'encounter_id',
        'sequence',
        'specimen_type',
        'body_site',
        'laterality',
        'description',
        'collected_by_user_id',
        'collected_at',
        'destination_laboratory',
        'lab_accession_ref',
        'status',
        'handed_over_by_user_id',
        'handed_over_at',
        'received_recorded_at',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): SurgicalSpecimenFactory
    {
        return SurgicalSpecimenFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'collected_at' => 'immutable_datetime',
            'handed_over_at' => 'immutable_datetime',
            'received_recorded_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $caseId,
        string $patientId,
        string $encounterId,
        int $sequence,
        string $specimenType,
        ?string $bodySite,
        ?string $laterality,
        ?string $description,
        string $collectedByUserId,
        ?string $destinationLaboratory,
        string $createdByUserId,
    ): self {
        $now = CarbonImmutable::now();

        $specimen = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'case_id' => $caseId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'sequence' => $sequence,
            'specimen_type' => $specimenType,
            'body_site' => $bodySite,
            'laterality' => $laterality,
            'description' => $description,
            'collected_by_user_id' => $collectedByUserId,
            'collected_at' => $now,
            'destination_laboratory' => $destinationLaboratory,
            'lab_accession_ref' => null,
            'status' => self::STATUS_COLLECTED,
            'handed_over_by_user_id' => null,
            'handed_over_at' => null,
            'received_recorded_at' => null,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $now,
            'recorded_at' => $now,
        ]);

        $specimen->recordThat(new SurgicalSpecimenCollected(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            surgicalSpecimenId: $id,
            caseId: $caseId,
            patientId: $patientId,
            encounterId: $encounterId,
            sequence: $sequence,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $specimen;
    }

    public function label(): void
    {
        $this->status = self::STATUS_LABELED;
    }

    public function handOff(string $destinationLaboratory, string $handedOverByUserId): void
    {
        $now = CarbonImmutable::now();
        $this->status = self::STATUS_HANDED_OVER;
        $this->destination_laboratory = $destinationLaboratory;
        $this->handed_over_by_user_id = $handedOverByUserId;
        $this->handed_over_at = $now;

        $this->recordThat(new SurgicalSpecimenHandedOff(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            surgicalSpecimenId: (string) $this->id,
            caseId: (string) $this->case_id,
            patientId: (string) $this->patient_id,
            destinationLaboratory: $destinationLaboratory,
            occurredAt: $now->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function markReceived(?string $labAccessionRef): void
    {
        $this->status = self::STATUS_RECEIVED;
        $this->received_recorded_at = CarbonImmutable::now();
        $this->lab_accession_ref = ($labAccessionRef !== null && trim($labAccessionRef) !== '') ? trim($labAccessionRef) : null;
    }
}
