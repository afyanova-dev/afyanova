<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a payer's approval for an authorization (21-insurance-and-payer-domain.md
 * §60–§62; ADR-202 D-B). Per-subject `approve` (`insurance.authorizations.approve`).
 *
 * An external authorization number + validTo are required (§276 Inv 23).
 * Approved quantity / amount below requested derives PARTIALLY_APPROVED (§62).
 */
final readonly class ApproveAuthorization implements Auditable, AuthorizesAgainst
{
    public string $externalAuthorizationNumber;

    public CarbonImmutable $validFrom;

    public CarbonImmutable $validTo;

    public ?string $approvedQuantity;

    public ?string $approvedAmount;

    public ?string $note;

    public function __construct(
        public string $authorizationId,
        public string $actorId,
        string $externalAuthorizationNumber,
        string $validFrom,
        string $validTo,
        ?string $approvedQuantity = null,
        ?string $approvedAmount = null,
        ?string $note = null,
    ) {
        foreach (['authorizationId' => $authorizationId, 'actorId' => $actorId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ApproveAuthorization::\${$field} must be a UUID (IC §3).");
            }
        }

        $this->externalAuthorizationNumber = trim($externalAuthorizationNumber);
        $this->approvedQuantity = self::clean($approvedQuantity);
        $this->approvedAmount = self::clean($approvedAmount);
        $this->note = self::clean($note);

        if ($this->approvedQuantity !== null && ! is_numeric($this->approvedQuantity)) {
            throw new InvalidArgumentException('ApproveAuthorization::$approvedQuantity must be numeric or null.');
        }

        if ($this->approvedAmount !== null && ! is_numeric($this->approvedAmount)) {
            throw new InvalidArgumentException('ApproveAuthorization::$approvedAmount must be numeric or null.');
        }

        $from = self::parseDate($validFrom, 'validFrom');
        $to = self::parseDate($validTo, 'validTo');

        if ($from === null) {
            throw new InvalidArgumentException('ApproveAuthorization::$validFrom must be a valid date.');
        }
        if ($to === null) {
            throw new InvalidArgumentException('ApproveAuthorization::$validTo must be a valid date.');
        }

        $this->validFrom = $from;
        $this->validTo = $to;
    }

    private static function parseDate(string $value, string $field): ?CarbonImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException("ApproveAuthorization::\${$field} must be a valid date.");
        }
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function policyAbility(): string
    {
        return 'approve';
    }

    public function subjectId(): string
    {
        return $this->authorizationId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.authorization_approved',
            actorId: $this->actorId,
            targetType: 'authorization',
            targetId: $this->authorizationId,
            metadata: [
                'external_authorization_number' => $this->externalAuthorizationNumber,
                'valid_from' => $this->validFrom->toDateString(),
                'valid_to' => $this->validTo->toDateString(),
            ],
        );
    }
}
