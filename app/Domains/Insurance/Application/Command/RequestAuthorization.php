<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Request a pre-authorization for a service category under a member's coverage
 * (21-insurance-and-payer-domain.md §56–§65; ADR-202 D-B).
 * Class-level `request` (`insurance.authorizations.request`).
 */
final readonly class RequestAuthorization implements Auditable, AuthorizesAgainst
{
    public string $serviceCategory;

    public ?string $serviceDescription;

    public ?string $diagnosisCode;

    public ?string $requestedQuantity;

    public ?string $requestedAmount;

    public function __construct(
        public string $authorizationId,
        public string $actorId,
        public string $memberCoverageId,
        string $serviceCategory,
        ?string $serviceDescription = null,
        ?string $diagnosisCode = null,
        public ?string $facilityId = null,
        public ?string $providerUserId = null,
        ?string $requestedQuantity = null,
        ?string $requestedAmount = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['authorizationId' => $authorizationId, 'actorId' => $actorId, 'memberCoverageId' => $memberCoverageId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RequestAuthorization::\${$field} must be a UUID (IC §3).");
            }
        }

        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('RequestAuthorization::$facilityId must be a UUID or null (IC §3).');
        }

        if ($providerUserId !== null && ! Str::isUuid($providerUserId)) {
            throw new InvalidArgumentException('RequestAuthorization::$providerUserId must be a UUID or null (IC §3).');
        }

        $this->serviceCategory = CoveredServiceCategory::assert($serviceCategory);
        $this->serviceDescription = self::clean($serviceDescription);
        $this->diagnosisCode = self::clean($diagnosisCode);
        $this->requestedQuantity = self::clean($requestedQuantity);
        $this->requestedAmount = self::clean($requestedAmount);

        if ($this->requestedQuantity !== null && ! is_numeric($this->requestedQuantity)) {
            throw new InvalidArgumentException('RequestAuthorization::$requestedQuantity must be numeric or null.');
        }

        if ($this->requestedAmount !== null && ! is_numeric($this->requestedAmount)) {
            throw new InvalidArgumentException('RequestAuthorization::$requestedAmount must be numeric or null.');
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
        return 'request';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.authorization_requested',
            actorId: $this->actorId,
            targetType: 'authorization',
            targetId: $this->authorizationId,
            metadata: [
                'member_coverage_id' => $this->memberCoverageId,
                'service_category' => $this->serviceCategory,
            ],
        );
    }
}
