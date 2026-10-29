<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Service;

use App\Domains\Patient\Domain\Enum\DuplicateOutcome;

/**
 * The detector's verdict on a registration (ADR-117). `matched` is ordered
 * strongest-first and is empty only when the outcome is Allow.
 */
final readonly class DuplicateAssessment
{
    /**
     * @param  list<DuplicateCandidate>  $matched
     */
    public function __construct(
        public DuplicateOutcome $outcome,
        public array $matched,
        public string $confidenceBand,
    ) {}

    public static function allow(): self
    {
        return new self(DuplicateOutcome::Allow, [], 'none');
    }

    /**
     * @param  list<DuplicateCandidate>  $matched
     */
    public static function review(array $matched, string $band): self
    {
        return new self(DuplicateOutcome::Review, $matched, $band);
    }

    /**
     * @param  list<DuplicateCandidate>  $matched
     */
    public static function block(array $matched, string $band): self
    {
        return new self(DuplicateOutcome::Block, $matched, $band);
    }

    /** @return list<string> */
    public function matchedPatientIds(): array
    {
        return array_map(static fn (DuplicateCandidate $c): string => $c->patientId, $this->matched);
    }
}
