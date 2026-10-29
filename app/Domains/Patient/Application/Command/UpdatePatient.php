<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Partial-patch update of a patient's core demographics (10-patient-domain.md
 * §26, §60–§61, §100, §130; ADR-127).
 *
 * The patch is a `changes` map: a key present means "patch this field", its
 * value is the new value (an explicit `null` clears a nullable field), a key
 * absent means "leave this field untouched" (ADR-127 rule 2 — omitted MUST be
 * distinguishable from explicit null; a map of scalars/null serialises cleanly
 * and needs no sentinel). Only the 9 existing `pat_patients` demographic
 * columns are patchable — no new columns, no status, no patient_number.
 *
 * `reason` is MANDATORY when the patch touches a sensitive identity field
 * (name parts, date of birth, dob precision, sex — ADR-127 rule 5); the
 * command is rejected at construction if it is missing. Authorization is
 * `patient.patients.update` (no new key). Not Auditable via the bus — the
 * before/after audit ADR-127 rule 4 requires is written by the handler, which
 * is the only place the field-level diff is known (same as RegisterPatient in
 * A.3).
 */
final readonly class UpdatePatient implements AuthorizesAgainst
{
    /** @var list<string> */
    public const EDITABLE = [
        'given_name', 'family_name', 'middle_name', 'display_name',
        'date_of_birth', 'dob_precision', 'sex', 'primary_phone', 'email',
    ];

    /** Fields that must never be set to null (NOT NULL columns). @var list<string> */
    private const NON_NULLABLE = ['given_name', 'family_name', 'display_name', 'dob_precision', 'sex'];

    /** Fields whose change requires a `reason` (ADR-127 rule 5). @var list<string> */
    public const SENSITIVE = [
        'given_name', 'family_name', 'middle_name', 'display_name',
        'date_of_birth', 'dob_precision', 'sex',
    ];

    /** @var array<string, string|null> validated patch map */
    public array $changes;

    /**
     * @param  array<string, string|null>  $changes
     */
    public function __construct(
        public string $patientId,
        public string $actorId,
        array $changes,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('UpdatePatient::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdatePatient::$actorId must be a UUID (IC §3).');
        }

        if ($changes === []) {
            throw new InvalidArgumentException('UpdatePatient::$changes must not be empty.');
        }

        $unknown = array_diff(array_keys($changes), self::EDITABLE);
        if ($unknown !== []) {
            throw new InvalidArgumentException('UpdatePatient: not a patchable demographic field: '.implode(', ', $unknown).'.');
        }

        $this->changes = $this->validated($changes);

        if ($this->touchesSensitiveField() && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('UpdatePatient::$reason is required when changing a sensitive demographic field.');
        }
    }

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): string
    {
        return $this->patientId; // per-subject check — the bus loads the patient (IC §5.1)
    }

    private function touchesSensitiveField(): bool
    {
        return array_intersect(array_keys($this->changes), self::SENSITIVE) !== [];
    }

    /**
     * @param  array<string, string|null>  $changes
     * @return array<string, string|null>
     */
    private function validated(array $changes): array
    {
        $out = [];

        foreach ($changes as $field => $value) {
            if ($value !== null) {
                $value = trim($value);
            }

            if ($value === null && in_array($field, self::NON_NULLABLE, true)) {
                throw new InvalidArgumentException("UpdatePatient: '{$field}' must not be null.");
            }

            if ($value === '' && in_array($field, self::NON_NULLABLE, true)) {
                throw new InvalidArgumentException("UpdatePatient: '{$field}' must not be blank.");
            }

            if ($field === 'sex' && Sex::tryFrom((string) $value) === null) {
                throw new InvalidArgumentException("UpdatePatient: '{$value}' is not a valid sex.");
            }

            if ($field === 'dob_precision' && DobPrecision::tryFrom((string) $value) === null) {
                throw new InvalidArgumentException("UpdatePatient: '{$value}' is not a valid dob_precision.");
            }

            if ($field === 'date_of_birth' && $value !== null && ! $this->isYmd($value)) {
                throw new InvalidArgumentException("UpdatePatient: date_of_birth must be YYYY-MM-DD, got '{$value}'.");
            }

            $out[$field] = $value === '' ? null : $value;
        }

        return $out;
    }

    private function isYmd(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
