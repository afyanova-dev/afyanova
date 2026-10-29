<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The optional standardized coding of a diagnosis (12-clinical-domain.md §24,
 * §102; ADR-139 D2).
 *
 * Both `code` and `code_system` are OPEN coded strings — there is NO
 * `DiagnosisCodeSystem` enum, NO terminology catalogue, NO ICD / ICD-11 /
 * SNOMED CT / local code validation, NO `TerminologyApi`, NO terminology
 * version and NO organization picklist in C.3 (the ADR-130 E / ADR-134
 * `note_type` / ADR-138 D3 "minimal, defer the heavy system" discipline).
 *
 * The ONLY rule this value object enforces is the ratified pairing (D2):
 *
 *   code absent   -> code_system absent
 *   code present  -> code_system required
 *
 * `code_system` is set once at `RecordDiagnosis` and is IMMUTABLE for the life
 * of that diagnosis row (§102) — a correction records a NEW `Diagnosis` (which
 * may carry a different coding) rather than mutating the original.
 */
final readonly class DiagnosisCode
{
    private const MAX_LENGTH = 100;

    private function __construct(
        public ?string $code,
        public ?string $codeSystem,
    ) {}

    /** A coded, or (both null) an un-coded, diagnosis. */
    public static function of(?string $code = null, ?string $codeSystem = null): self
    {
        $code = ($code !== null && trim($code) !== '') ? trim($code) : null;
        $codeSystem = ($codeSystem !== null && trim($codeSystem) !== '') ? trim($codeSystem) : null;

        if ($code === null && $codeSystem !== null) {
            throw new InvalidArgumentException(
                'DiagnosisCode: a code_system without a code is not permitted.'
            );
        }

        if ($code !== null && $codeSystem === null) {
            throw new InvalidArgumentException(
                'DiagnosisCode: a code requires a code_system.'
            );
        }

        foreach (['code' => $code, 'code_system' => $codeSystem] as $name => $value) {
            if ($value !== null && mb_strlen($value) > self::MAX_LENGTH) {
                throw new InvalidArgumentException(sprintf(
                    'DiagnosisCode::%s must be at most %d characters.',
                    $name,
                    self::MAX_LENGTH,
                ));
            }
        }

        return new self($code, $codeSystem);
    }

    /** An explicitly un-coded diagnosis (description only). */
    public static function none(): self
    {
        return new self(null, null);
    }

    public function isCoded(): bool
    {
        return $this->code !== null;
    }
}
