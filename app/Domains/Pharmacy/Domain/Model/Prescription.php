<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Model;

use App\Domains\Clinical\Contracts\Dto\PrescribingDecisionSummary;
use App\Domains\Pharmacy\Contracts\Event\PrescriptionAccepted;
use App\Domains\Pharmacy\Contracts\Event\PrescriptionCancelled;
use App\Domains\Pharmacy\Contracts\Event\TranscriptionAccepted;
use App\Domains\Pharmacy\Database\Factories\PrescriptionFactory;
use App\Domains\Pharmacy\Domain\ValueObject\PrescriptionStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * The Prescription record aggregate (16-pharmacy-domain.md §6, §19, §20, §21,
 * §111, §253; ADR-181 D-D).
 *
 * Pharmacy creates and owns the authoritative Prescription RECORD on receipt of
 * Clinical's `clinical.PrescriptionIssued` (Clinical owns the prescribing
 * DECISION — there is no competing authoritative copy in Clinical, §19).
 *
 * A `RECEIVED` stub is minted from the PHI-minimal event payload only
 * ({@see self::ingest()}); an authenticated {@see self::accept()} writes the
 * immutable, SET-ONCE clinical SNAPSHOT (medication / dose / quantity / …) from
 * the `PrescribingDecisionSummary` a pharmacist fetched through the protected
 * `ClinicalApi::getPrescribingDecision`, and moves RECEIVED → ACTIVE.
 * {@see self::cancel()} is the administrative terminal transition. `status` is
 * an open-coded string constant (the `StockAdjustment` precedent — NOT a PHP
 * enum, NOT `#[TypeScript]`).
 *
 * @domain      Pharmacy
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string|null $prescribing_decision_id
 * @property string $patient_id
 * @property string|null $encounter_id
 * @property string $prescription_number
 * @property string $author_user_id
 * @property string|null $change_type
 * @property string|null $medication_code_system
 * @property string|null $route
 * @property string|null $urgency
 * @property string|null $medication_name
 * @property string|null $medication_code
 * @property string|null $inventory_item_id
 * @property string|null $external_intake_id
 * @property string|null $dose
 * @property string|null $frequency
 * @property string|null $duration
 * @property string|null $prescribed_quantity
 * @property string|null $dosing_instruction
 * @property string $status
 * @property string|null $accepted_by_user_id
 * @property string|null $cancelled_by_user_id
 * @property string|null $cancel_reason
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Prescription extends AggregateRoot
{
    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory;

    public const STATUS_RECEIVED = PrescriptionStatus::RECEIVED;

    public const SOURCE_ELECTRONIC = 'ELECTRONIC';

    public const SOURCE_PAPER_TRANSCRIPTION = 'PAPER_TRANSCRIPTION';

    public const SOURCE_EXTERNAL_PAPER = 'EXTERNAL_PAPER';

    public const STATUS_ACTIVE = PrescriptionStatus::ACTIVE;

    public const STATUS_CANCELLED = PrescriptionStatus::CANCELLED;

    protected $table = 'rx_prescriptions';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id',
        'prescribing_decision_id', 'patient_id', 'encounter_id',
        'prescription_number', 'author_user_id', 'change_type',
        'medication_code_system', 'route', 'urgency',
        'medication_name', 'medication_code', 'inventory_item_id', 'dose', 'frequency', 'duration',
        'prescribed_quantity', 'dosing_instruction',
        'source', 'prescriber_name', 'transcribed_by_user_id', 'transcription_note', 'external_intake_id',
        'status', 'accepted_by_user_id', 'cancelled_by_user_id', 'cancel_reason',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): PrescriptionFactory
    {
        return PrescriptionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * Mint the RECEIVED stub from the `clinical.PrescriptionIssued` payload only
     * (ADR-181 C1 / D-E). Records NO event — intake is not a decision.
     */
    public static function ingest(
        string $id,
        string $tenantId,
        string $facilityId,
        string $prescribingDecisionId,
        string $patientId,
        string $encounterId,
        string $prescriptionNumber,
        string $authorUserId,
        ?string $changeType,
        ?string $medicationCodeSystem,
        ?string $route,
        ?string $urgency,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'prescribing_decision_id' => $prescribingDecisionId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'prescription_number' => $prescriptionNumber,
            'author_user_id' => $authorUserId,
            'change_type' => $changeType !== null && trim($changeType) !== '' ? trim($changeType) : null,
            'medication_code_system' => self::clean($medicationCodeSystem),
            'route' => self::clean($route),
            'urgency' => self::clean($urgency),
            'status' => self::STATUS_RECEIVED,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /**
     * RECEIVED → ACTIVE. Writes the immutable, SET-ONCE clinical snapshot from
     * the prescribing decision the pharmacist fetched under their own
     * authorization (ADR-181 D-D). Records `PrescriptionAccepted`.
     */
    public function accept(PrescribingDecisionSummary $decision, string $acceptedByUserId): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->accepted_by_user_id = $acceptedByUserId;

        $this->medication_name = self::clean($decision->medicationName);
        $this->medication_code = self::clean($decision->medicationCode);
        $this->medication_code_system = self::clean($decision->medicationCodeSystem) ?? $this->medication_code_system;
        $this->dose = self::clean($decision->dose);
        $this->route = self::clean($decision->route) ?? $this->route;
        $this->frequency = self::clean($decision->frequency);
        $this->duration = self::clean($decision->duration);
        $this->prescribed_quantity = self::clean($decision->quantity);
        $this->dosing_instruction = self::clean($decision->dosingInstruction);
        $this->urgency = self::clean($decision->urgency) ?? $this->urgency;

        $this->recordThat(new PrescriptionAccepted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            prescriptionId: (string) $this->id,
            prescribingDecisionId: (string) $this->prescribing_decision_id,
            acceptedByUserId: $acceptedByUserId,
            medicationCodeSystem: $this->medication_code_system,
            route: $this->route,
            urgency: $this->urgency,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * RECEIVED | ACTIVE → CANCELLED — administrative (ADR-181 D-D). `reason` is
     * ROW-ONLY (the ADR-136 deny-list). Records `PrescriptionCancelled`.
     */
    public function cancel(string $cancelledByUserId, string $reason): void
    {
        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_by_user_id = $cancelledByUserId;
        $this->cancel_reason = trim($reason);

        $this->recordThat(new PrescriptionCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            prescriptionId: (string) $this->id,
            prescribingDecisionId: $this->prescribing_decision_id !== null ? (string) $this->prescribing_decision_id : null,
            cancelledByUserId: $cancelledByUserId,
            reason: self::STATUS_CANCELLED,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * Mint a RECEIVED stub from a paper prescription the pharmacist transcribes
     * (Pharm.4). No clinical decision exists — provenance (prescriber verbatim +
     * transcriber) is stored instead of a decision link, and the medication
     * snapshot is typed from the paper at accept-time, not fetched.
     */
    public static function transcribe(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        ?string $encounterId,
        string $prescriptionNumber,
        string $prescriberName,
        string $transcribedByUserId,
        ?string $transcriptionNote,
        ?string $externalIntakeId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'prescribing_decision_id' => null,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'prescription_number' => $prescriptionNumber,
            'author_user_id' => $transcribedByUserId,
            'source' => self::SOURCE_EXTERNAL_PAPER,
            'prescriber_name' => trim($prescriberName),
            'transcribed_by_user_id' => $transcribedByUserId,
            'transcription_note' => $transcriptionNote !== null && trim($transcriptionNote) !== '' ? trim($transcriptionNote) : null,
            'external_intake_id' => $externalIntakeId,
            'status' => self::STATUS_RECEIVED,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /**
     * RECEIVED → ACTIVE for a transcribed paper prescription. The medication
     * snapshot is typed from the paper (no decision to fetch) — same SET-ONCE
     * semantics as the electronic path. Records `TranscriptionAccepted`.
     *
     * @param  array{medication_name: string, medication_code: string, inventory_item_id: string, dose?: ?string, frequency?: ?string, duration?: ?string, prescribed_quantity?: ?string, dosing_instruction?: ?string, route?: ?string, urgency?: ?string}  $medication
     */
    public function acceptTranscription(array $medication, string $acceptedByUserId): void
    {
        $this->status = self::STATUS_ACTIVE;
        $this->accepted_by_user_id = $acceptedByUserId;

        $this->medication_name = self::clean($medication['medication_name']);
        $this->medication_code = self::clean($medication['medication_code']);
        $this->inventory_item_id = $medication['inventory_item_id'];
        $this->dose = self::clean($medication['dose'] ?? null);
        $this->route = self::clean($medication['route'] ?? null) ?? $this->route;
        $this->frequency = self::clean($medication['frequency'] ?? null);
        $this->duration = self::clean($medication['duration'] ?? null);
        $this->prescribed_quantity = self::clean($medication['prescribed_quantity'] ?? null);
        $this->dosing_instruction = self::clean($medication['dosing_instruction'] ?? null);
        $this->urgency = self::clean($medication['urgency'] ?? null) ?? $this->urgency;

        $this->recordThat(new TranscriptionAccepted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            prescriptionId: (string) $this->id,
            prescriberName: (string) $this->prescriber_name,
            acceptedByUserId: $acceptedByUserId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    private static function clean(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
