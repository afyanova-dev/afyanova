<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\ValueObject\PayerType;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Register a payer organization (21-insurance-and-payer-domain.md §8, §9, §10;
 * ADR-198 D-G). Class-level `manage` (`insurance.payers.manage`).
 *
 * `code` is SET-ONCE (immutable after this command); tenant-unique →
 * `PayerCodeTaken` on the race. `SELF_PAY` is not a valid `payerType` (§141).
 */
final readonly class RegisterPayer implements Auditable, AuthorizesAgainst
{
    public string $code;

    public string $name;

    public string $payerType;

    public ?string $contactName;

    public ?string $contactEmail;

    public ?string $contactPhone;

    public function __construct(
        public string $payerId,
        public string $actorId,
        string $code,
        string $name,
        string $payerType,
        ?string $contactName = null,
        ?string $contactEmail = null,
        ?string $contactPhone = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($payerId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RegisterPayer ids must be UUIDs (IC §3).');
        }

        $code = trim($code);
        if ($code === '' || mb_strlen($code) > 64) {
            throw new InvalidArgumentException('RegisterPayer::$code must be 1–64 characters.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('RegisterPayer::$name is required.');
        }

        $this->code = $code;
        $this->name = $name;
        $this->payerType = PayerType::assert($payerType);
        $this->contactName = self::clean($contactName);
        $this->contactEmail = self::clean($contactEmail);
        $this->contactPhone = self::clean($contactPhone);
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
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.payer_registered',
            actorId: $this->actorId,
            targetType: 'payer',
            targetId: $this->payerId,
            metadata: ['code' => $this->code, 'payer_type' => $this->payerType],
        );
    }
}
