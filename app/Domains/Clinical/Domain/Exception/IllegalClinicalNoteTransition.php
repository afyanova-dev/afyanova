<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Domains\Clinical\Domain\Enum\ClinicalNoteStatus;
use App\Shared\Domain\DomainException;

/**
 * A clinical-note lifecycle command was dispatched against a note it cannot
 * legally act on — the current status is not a legal source for that command's
 * transition (12-clinical-domain.md §12, §13, §15, Invariant 5; ADR-134 —
 * "invalid transitions raise a domain exception"; the `IllegalEncounterTransition`
 * precedent). Validated by `Clinical\Domain\Service\ClinicalNoteLifecycle`
 * before any mutation. Maps to HTTP 409 (IC §15).
 *
 *   - `SignClinicalNote`   — legal only from `DRAFT`; a `SIGNED` note is not
 *                            re-signed (not idempotent), a `CANCELLED` note is
 *                            discarded.
 *   - `CancelClinicalNote` — legal only from `DRAFT`; a `SIGNED` note is a
 *                            high-integrity record (corrections are amendments),
 *                            a `CANCELLED` note is already discarded.
 *   - `AmendClinicalNote`  — legal only from `SIGNED`; a `DRAFT` is edited
 *                            before signing (not in C.1) and a `CANCELLED` note
 *                            carries nothing to amend.
 */
final class IllegalClinicalNoteTransition extends DomainException
{
    public static function for(string $command, ClinicalNoteStatus $current): self
    {
        return new self(sprintf(
            '%s is not a legal transition from status %s.',
            $command,
            $current->value,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_clinical_note_transition';
    }
}
