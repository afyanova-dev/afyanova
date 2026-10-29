<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The allergen an allergy assertion is about (12-clinical-domain.md §30, §32,
 * §75; ADR-141 D-E).
 *
 *   substance_name         REQUIRED — trimmed, non-blank clinical text
 *   substance_code         OPTIONAL — an OPEN string, no validation
 *   substance_code_system  OPTIONAL — an OPEN string; REQUIRED iff a code is
 *                                     present, FORBIDDEN without one
 *
 * There is NO `AllergySubstanceCodeSystem` enum, NO `TerminologyApi`, NO
 * SNOMED CT / RxNorm / ATC / ICD / ICD-11 / local-code validation, NO code
 * catalogue, NO terminology versioning, NO autocomplete / lookup (the ADR-134
 * `note_type` / ADR-139 D2 / ADR-140 D-G "minimal, defer the heavy system"
 * discipline). `code` / `code_system` are MUTABLE via `UpdateAllergy`; the
 * pre-change value is preserved in `clin_allergy_history` (ADR-141 D-E — the
 * bounded-mutation + append-only-history model, the ADR-140 D-G choice, NOT the
 * C.3 set-once model).
 *
 * The ONLY rules this value object enforces are the required name and the
 * ratified code / code-system pairing (D-E).
 */
final readonly class AllergySubstance
{
    private const MAX_NAME_LENGTH = 500;

    private const MAX_CODE_LENGTH = 100;

    private function __construct(
        public string $name,
        public ?string $code,
        public ?string $codeSystem,
    ) {}

    /**
     * A named allergen, optionally coded.
     */
    public static function of(string $name, ?string $code = null, ?string $codeSystem = null): self
    {
        $name = trim($name);
        $code = ($code !== null && trim($code) !== '') ? trim($code) : null;
        $codeSystem = ($codeSystem !== null && trim($codeSystem) !== '') ? trim($codeSystem) : null;

        if ($name === '') {
            throw new InvalidArgumentException(
                'AllergySubstance::$name must not be blank.'
            );
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'AllergySubstance::$name must be at most %d characters.',
                self::MAX_NAME_LENGTH,
            ));
        }

        if ($code === null && $codeSystem !== null) {
            throw new InvalidArgumentException(
                'AllergySubstance: a code_system without a code is not permitted.'
            );
        }

        if ($code !== null && $codeSystem === null) {
            throw new InvalidArgumentException(
                'AllergySubstance: a code requires a code_system.'
            );
        }

        foreach (['code' => $code, 'code_system' => $codeSystem] as $field => $value) {
            if ($value !== null && mb_strlen($value) > self::MAX_CODE_LENGTH) {
                throw new InvalidArgumentException(sprintf(
                    'AllergySubstance::%s must be at most %d characters.',
                    $field,
                    self::MAX_CODE_LENGTH,
                ));
            }
        }

        return new self($name, $code, $codeSystem);
    }

    public function isCoded(): bool
    {
        return $this->code !== null;
    }
}
