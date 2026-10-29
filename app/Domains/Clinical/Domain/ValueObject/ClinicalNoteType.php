<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The classifier for a clinical note — "progress note", "consultation note",
 * "discharge summary", … (12-clinical-domain.md §10; ADR-134).
 *
 * An OPEN coded string, NOT a closed `#[TypeScript]` enum: `12` §10 requires
 * note types to "remain configurable / extensible", and templates / per-type
 * required fields are deferred (ADR-134). This value object therefore validates
 * only SHAPE — a non-empty, trimmed, reasonably-bounded token — and carries no
 * behaviour. Nothing in `App\Domains\Clinical\Domain\Service` or
 * `App\Domains\Clinical\Authorization` reads the note type: it never branches
 * lifecycle, authorization, or validation (the C.1 NoteType invariant). It is a
 * search / display / reporting classifier only.
 */
final readonly class ClinicalNoteType
{
    private const MAX_LENGTH = 100;

    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException('ClinicalNoteType must not be blank (12 §10).');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                'ClinicalNoteType must be at most '.self::MAX_LENGTH.' characters (12 §10).'
            );
        }

        return new self($trimmed);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
