<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The optional standardized coding of a longitudinal problem
 * (12-clinical-domain.md §24, §102; ADR-140 D-G).
 *
 * Both `code` and `code_system` are OPEN coded strings — there is NO
 * `ProblemCodeSystem` enum, NO terminology catalogue, NO ICD / ICD-11 /
 * SNOMED CT / local code validation, NO `TerminologyApi`, NO terminology
 * version and NO organization picklist in C.4 (the ADR-134 `note_type` /
 * ADR-139 D2 "minimal, defer the heavy system" discipline).
 *
 * The ONLY rule this value object enforces is the ratified pairing (D-G):
 *
 *   code absent   -> code_system absent
 *   code present  -> code_system required
 *
 * Unlike C.3 `DiagnosisCode` (`code_system` set once, immutable), a problem's
 * coding is MUTABLE via `UpdateProblem` — the pre-change value is preserved in
 * `clin_problem_history` (ADR-140 D-G), a deliberate C.4 choice consistent with
 * the bounded-mutation + append-only-history model.
 */
final readonly class ProblemCode
{
    private const MAX_LENGTH = 100;

    private function __construct(
        public ?string $code,
        public ?string $codeSystem,
    ) {}

    /** A coded, or (both null) an un-coded, problem. */
    public static function of(?string $code = null, ?string $codeSystem = null): self
    {
        $code = ($code !== null && trim($code) !== '') ? trim($code) : null;
        $codeSystem = ($codeSystem !== null && trim($codeSystem) !== '') ? trim($codeSystem) : null;

        if ($code === null && $codeSystem !== null) {
            throw new InvalidArgumentException(
                'ProblemCode: a code_system without a code is not permitted.'
            );
        }

        if ($code !== null && $codeSystem === null) {
            throw new InvalidArgumentException(
                'ProblemCode: a code requires a code_system.'
            );
        }

        foreach (['code' => $code, 'code_system' => $codeSystem] as $name => $value) {
            if ($value !== null && mb_strlen($value) > self::MAX_LENGTH) {
                throw new InvalidArgumentException(sprintf(
                    'ProblemCode::%s must be at most %d characters.',
                    $name,
                    self::MAX_LENGTH,
                ));
            }
        }

        return new self($code, $codeSystem);
    }

    /** An explicitly un-coded problem (description only). */
    public static function none(): self
    {
        return new self(null, null);
    }

    public function isCoded(): bool
    {
        return $this->code !== null;
    }
}
