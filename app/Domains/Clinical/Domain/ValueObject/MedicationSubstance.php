<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The medication an immutable PrescribingDecision is about (ADR-143 D-I).
 *
 *   name        REQUIRED — trimmed, non-blank clinical text (e.g. "Amoxicillin")
 *   code        OPTIONAL — an OPEN string, no terminology validation
 *   codeSystem  OPTIONAL — an OPEN string; REQUIRED iff a code is present, FORBIDDEN without one
 *
 * Backend-only, immutable VO.
 */
final readonly class MedicationSubstance
{
    private const MAX_NAME_LENGTH = 500;

    private const MAX_CODE_LENGTH = 100;

    private function __construct(
        public string $name,
        public ?string $code,
        public ?string $codeSystem,
    ) {}

    public static function of(string $name, ?string $code = null, ?string $codeSystem = null): self
    {
        $name = trim($name);
        $code = ($code !== null && trim($code) !== '') ? trim($code) : null;
        $codeSystem = ($codeSystem !== null && trim($codeSystem) !== '') ? trim($codeSystem) : null;

        if ($name === '') {
            throw new InvalidArgumentException(
                'MedicationSubstance::$name must not be blank.'
            );
        }

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'MedicationSubstance::$name must be at most %d characters.',
                self::MAX_NAME_LENGTH,
            ));
        }

        if ($code === null && $codeSystem !== null) {
            throw new InvalidArgumentException(
                'MedicationSubstance: a code_system without a code is not permitted.'
            );
        }

        if ($code !== null && $codeSystem === null) {
            throw new InvalidArgumentException(
                'MedicationSubstance: a code requires a code_system.'
            );
        }

        foreach (['code' => $code, 'code_system' => $codeSystem] as $field => $value) {
            if ($value !== null && mb_strlen($value) > self::MAX_CODE_LENGTH) {
                throw new InvalidArgumentException(sprintf(
                    'MedicationSubstance::%s must be at most %d characters.',
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
