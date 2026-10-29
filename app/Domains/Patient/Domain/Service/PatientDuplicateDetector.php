<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Service;

use App\Domains\Patient\Domain\DuplicateCandidateReader;
use App\Domains\Patient\Domain\Enum\Sex;

/**
 * The PHASE 5 default duplicate-matching policy (ADR-117). It reads a bounded
 * candidate set through a port, scores each candidate on the canonical signal
 * set, and maps the top score to the fixed BLOCK / REVIEW / ALLOW contract.
 *
 * The weights and thresholds below are the shipped default. §160 makes
 * "duplicate matching thresholds" tenant configuration — wiring that config in
 * is a later phase; the outcome contract this returns does not change.
 *
 * Pure apart from the injected reader — no DB access (IC §9).
 */
final readonly class PatientDuplicateDetector
{
    private const WEIGHT_FULL_NAME = 40;

    private const WEIGHT_FAMILY_NAME = 20;

    private const WEIGHT_DOB = 35;

    private const WEIGHT_SEX = 10;

    private const WEIGHT_PHONE = 35;

    private const BLOCK_AT = 85;

    private const REVIEW_AT = 55;

    public function __construct(private DuplicateCandidateReader $reader) {}

    public function assess(DuplicateSignals $signals): DuplicateAssessment
    {
        $candidates = $this->reader->candidatesFor($signals);

        if ($candidates === []) {
            return DuplicateAssessment::allow();
        }

        /** @var list<array{int, DuplicateCandidate}> $scored */
        $scored = [];
        foreach ($candidates as $candidate) {
            // ADR-117: an exact match on an existing VERIFIED national / insurance
            // identifier is, on its own, a BLOCK signal.
            $score = $candidate->hasVerifiedIdentifierMatch
                ? self::BLOCK_AT + 100
                : $this->score($signals, $candidate);

            $scored[] = [$score, $candidate];
        }

        usort($scored, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        $topScore = $scored[0][0];

        /** @var list<DuplicateCandidate> $matched */
        $matched = array_values(array_map(
            static fn (array $row): DuplicateCandidate => $row[1],
            array_filter($scored, static fn (array $row): bool => $row[0] >= self::REVIEW_AT),
        ));

        if ($topScore >= self::BLOCK_AT) {
            return DuplicateAssessment::block($matched, $this->band($topScore));
        }

        if ($topScore >= self::REVIEW_AT) {
            return DuplicateAssessment::review($matched, $this->band($topScore));
        }

        return DuplicateAssessment::allow();
    }

    private function score(DuplicateSignals $signals, DuplicateCandidate $candidate): int
    {
        $score = 0;

        if ($signals->normalizedName === $candidate->normalizedName) {
            $score += self::WEIGHT_FULL_NAME;
        } elseif ($signals->normalizedFamilyName === $candidate->normalizedFamilyName) {
            $score += self::WEIGHT_FAMILY_NAME;
        }

        if ($signals->dateOfBirth !== null && $signals->dateOfBirth === $candidate->dateOfBirth) {
            $score += self::WEIGHT_DOB;
        }

        if ($signals->sex !== Sex::Unknown && $signals->sex->value === $candidate->sex) {
            $score += self::WEIGHT_SEX;
        }

        if ($signals->normalizedPhone !== null && $signals->normalizedPhone === $candidate->normalizedPhone) {
            $score += self::WEIGHT_PHONE;
        }

        return $score;
    }

    private function band(int $score): string
    {
        return match (true) {
            $score >= self::BLOCK_AT => 'high',
            $score >= self::REVIEW_AT => 'medium',
            default => 'low',
        };
    }
}
