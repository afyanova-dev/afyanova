<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use App\Domains\Insurance\Database\Factories\AuthorizationFactory;
use App\Domains\Insurance\Domain\Exception\AuthorizationDecisionIncomplete;
use App\Domains\Insurance\Domain\Exception\IllegalAuthorizationTransition;
use App\Domains\Insurance\Domain\ValueObject\AuthorizationStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * The local record of a payer's pre-authorization decision
 * (21-insurance-and-payer-domain.md §56–§65, §248; Authorization Invariants
 * §276 Inv 23–25; ADR-202 D-A).
 *
 * AfyaNova RECORDS the payer's decision (with the payer's authorization number)
 * — it does not GRANT approval (§287). `member_coverage_id` / `patient_id` /
 * `plan_id` / `payer_id` / `service_category` are SET-ONCE (`payer_id` etc.
 * copied from the member coverage). Terminal DENIED / EXPIRED / CANCELLED — the
 * row is never deleted (§64). No events (§234's consumers unbuilt — ADR-137).
 *
 * @domain      Insurance
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $authorization_number
 * @property string $member_coverage_id
 * @property string $patient_id
 * @property string $plan_id
 * @property string $payer_id
 * @property string $service_category
 * @property string|null $service_description
 * @property string|null $diagnosis_code
 * @property string|null $facility_id
 * @property string|null $provider_user_id
 * @property string|null $requested_quantity
 * @property string|null $requested_amount
 * @property string $status
 * @property string|null $external_authorization_number
 * @property string|null $approved_quantity
 * @property string|null $approved_amount
 * @property CarbonImmutable|null $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property string|null $decision_reason
 * @property string $requested_by_user_id
 * @property CarbonImmutable $requested_at
 * @property string|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $expired_at
 * @property string|null $cancelled_by_user_id
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $created_by_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class Authorization extends AggregateRoot
{
    /** @use HasFactory<AuthorizationFactory> */
    use HasFactory;

    public const STATUS_REQUESTED = AuthorizationStatus::REQUESTED;

    public const STATUS_APPROVED = AuthorizationStatus::APPROVED;

    public const STATUS_PARTIALLY_APPROVED = AuthorizationStatus::PARTIALLY_APPROVED;

    public const STATUS_DENIED = AuthorizationStatus::DENIED;

    public const STATUS_EXPIRED = AuthorizationStatus::EXPIRED;

    public const STATUS_CANCELLED = AuthorizationStatus::CANCELLED;

    protected $table = 'ins_authorizations';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'authorization_number',
        'member_coverage_id', 'patient_id', 'plan_id', 'payer_id',
        'service_category', 'service_description', 'diagnosis_code',
        'facility_id', 'provider_user_id',
        'requested_quantity', 'requested_amount',
        'status', 'external_authorization_number',
        'approved_quantity', 'approved_amount', 'valid_from', 'valid_to',
        'decision_reason',
        'requested_by_user_id', 'requested_at',
        'decided_by_user_id', 'decided_at',
        'expired_at', 'cancelled_by_user_id', 'cancellation_reason', 'cancelled_at',
        'created_by_user_id',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): AuthorizationFactory
    {
        return AuthorizationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:4',
            'requested_amount' => 'decimal:4',
            'approved_quantity' => 'decimal:4',
            'approved_amount' => 'decimal:4',
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function request(
        string $id,
        string $tenantId,
        string $authorizationNumber,
        string $memberCoverageId,
        string $patientId,
        string $planId,
        string $payerId,
        string $serviceCategory,
        ?string $serviceDescription,
        ?string $diagnosisCode,
        ?string $facilityId,
        ?string $providerUserId,
        ?string $requestedQuantity,
        ?string $requestedAmount,
        string $requestedByUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'authorization_number' => $authorizationNumber,
            'member_coverage_id' => $memberCoverageId,
            'patient_id' => $patientId,
            'plan_id' => $planId,
            'payer_id' => $payerId,
            'service_category' => $serviceCategory,
            'service_description' => $serviceDescription,
            'diagnosis_code' => $diagnosisCode,
            'facility_id' => $facilityId,
            'provider_user_id' => $providerUserId,
            'requested_quantity' => $requestedQuantity,
            'requested_amount' => $requestedAmount,
            'status' => self::STATUS_REQUESTED,
            'external_authorization_number' => null,
            'approved_quantity' => null,
            'approved_amount' => null,
            'valid_from' => null,
            'valid_to' => null,
            'decision_reason' => null,
            'requested_by_user_id' => $requestedByUserId,
            'requested_at' => $occurredAt,
            'decided_by_user_id' => null,
            'decided_at' => null,
            'expired_at' => null,
            'cancelled_by_user_id' => null,
            'cancellation_reason' => null,
            'cancelled_at' => null,
            'created_by_user_id' => $requestedByUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /**
     * REQUESTED -> APPROVED | PARTIALLY_APPROVED. The external number + `validTo`
     * are REQUIRED (§60 / §276 Inv 23). PARTIALLY_APPROVED whenever an approved
     * quantity or amount is below the requested value (§62).
     */
    public function approve(
        string $externalNumber,
        ?string $approvedQuantity,
        ?string $approvedAmount,
        CarbonImmutable $validFrom,
        ?CarbonImmutable $validTo,
        ?string $note,
        string $byUserId,
        CarbonImmutable $at,
    ): void {
        if ($this->status !== self::STATUS_REQUESTED) {
            throw IllegalAuthorizationTransition::fromStatus('ApproveAuthorization', $this->status);
        }

        $externalNumber = trim($externalNumber);
        if ($externalNumber === '') {
            throw AuthorizationDecisionIncomplete::missingExternalNumber();
        }
        if ($validTo === null) {
            throw AuthorizationDecisionIncomplete::missingValidTo();
        }
        if ($validFrom->gt($validTo)) {
            throw AuthorizationDecisionIncomplete::invalidWindow();
        }

        $partial = false;
        if ($approvedQuantity !== null && $this->requested_quantity !== null
            && is_numeric($approvedQuantity) && is_numeric((string) $this->requested_quantity)) {
            $partial = bccomp((string) $approvedQuantity, (string) $this->requested_quantity, 4) < 0;
        }
        if (! $partial && $approvedAmount !== null && $this->requested_amount !== null
            && is_numeric($approvedAmount) && is_numeric((string) $this->requested_amount)) {
            $partial = bccomp((string) $approvedAmount, (string) $this->requested_amount, 4) < 0;
        }

        $this->status = $partial ? self::STATUS_PARTIALLY_APPROVED : self::STATUS_APPROVED;
        $this->external_authorization_number = $externalNumber;
        $this->approved_quantity = $approvedQuantity;
        $this->approved_amount = $approvedAmount;
        $this->valid_from = $validFrom;
        $this->valid_to = $validTo;
        $this->decision_reason = $note !== null && trim($note) !== '' ? trim($note) : null;
        $this->decided_by_user_id = $byUserId;
        $this->decided_at = $at;
    }

    /** REQUESTED -> DENIED. */
    public function deny(string $reasonCode, ?string $note, string $byUserId, CarbonImmutable $at): void
    {
        if ($this->status !== self::STATUS_REQUESTED) {
            throw IllegalAuthorizationTransition::fromStatus('DenyAuthorization', $this->status);
        }

        $this->status = self::STATUS_DENIED;
        $this->decision_reason = $note !== null && trim($note) !== '' ? trim($reasonCode).' — '.trim($note) : trim($reasonCode);
        $this->decided_by_user_id = $byUserId;
        $this->decided_at = $at;
    }

    /** {REQUESTED | APPROVED | PARTIALLY_APPROVED} -> CANCELLED (§64). */
    public function cancel(string $reasonCode, string $byUserId, CarbonImmutable $at): void
    {
        if (! in_array($this->status, AuthorizationStatus::CANCELLABLE, true)) {
            throw IllegalAuthorizationTransition::fromStatus('CancelAuthorization', $this->status);
        }

        $this->status = self::STATUS_CANCELLED;
        $this->cancellation_reason = trim($reasonCode);
        $this->cancelled_by_user_id = $byUserId;
        $this->cancelled_at = $at;
    }

    /** {APPROVED | PARTIALLY_APPROVED} -> EXPIRED. Idempotent (the sweep may double-run). */
    public function expire(CarbonImmutable $at): void
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return;
        }
        if (! in_array($this->status, AuthorizationStatus::EFFECTIVE, true)) {
            throw IllegalAuthorizationTransition::fromStatus('ExpireAuthorization', $this->status);
        }

        $this->status = self::STATUS_EXPIRED;
        $this->expired_at = $at;
    }

    /** Check-at-use (§276 Inv 25): effective status AND `date` within the validity window. */
    public function isEffectiveOn(CarbonImmutable $date): bool
    {
        if (! in_array($this->status, AuthorizationStatus::EFFECTIVE, true)) {
            return false;
        }
        if ($this->valid_from !== null && $this->valid_from->gt($date)) {
            return false;
        }
        if ($this->valid_to !== null && $this->valid_to->lt($date)) {
            return false;
        }

        return true;
    }
}
