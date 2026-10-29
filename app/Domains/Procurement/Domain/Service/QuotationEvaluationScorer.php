<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Service;

use App\Domains\Procurement\Domain\EvaluationCriteria;
use App\Domains\Procurement\Domain\EvaluationScores;
use InvalidArgumentException;

/**
 * The evaluation scoring policy (19-procurement-domain.md §36; ADR-174 D-J,
 * amended by ADR-175 D-D). Pure — no I/O.
 *
 * The four §36 dimensions are weighted into a single `overall` score. The
 * weights and which dimensions are mandatory come from `EvaluationCriteria`
 * (the tenant's `pcr_evaluation_settings`, or the shipped 40 / 30 / 15 / 15
 * default). A dimension score may be null — for a DRAFT, or a dimension the
 * tenant marked optional — and `overall` is then the weighted mean over the
 * PRESENT dimensions only (no weight re-normalisation config).
 */
final class QuotationEvaluationScorer
{
    public const MIN_SCORE = 0;

    public const MAX_SCORE = 100;

    /**
     * The weighted mean of the PRESENT dimension scores, rounded to a 0..100
     * integer, using `$criteria`'s weights. At least one dimension must carry a
     * score (the caller — `SubmitEvaluationHandler` — has already enforced the
     * mandatory set).
     */
    public function overall(EvaluationScores $scores, EvaluationCriteria $criteria): int
    {
        $weights = $criteria->weights();

        $weighted = 0;
        $totalWeight = 0;

        foreach ($scores->present() as $dimension) {
            $score = (int) $scores->get($dimension);
            $this->assertScoreInRange($score, $dimension);

            $weighted += $score * $weights[$dimension];
            $totalWeight += $weights[$dimension];
        }

        if ($totalWeight <= 0) {
            throw new InvalidArgumentException('QuotationEvaluationScorer::overall needs at least one scored dimension with a positive weight.');
        }

        return (int) round($weighted / $totalWeight);
    }

    public function assertScoreInRange(int $score, string $dimension): void
    {
        if ($score < self::MIN_SCORE || $score > self::MAX_SCORE) {
            throw new InvalidArgumentException(sprintf(
                'Evaluation %s score must be %d..%d, got %d.',
                $dimension,
                self::MIN_SCORE,
                self::MAX_SCORE,
                $score,
            ));
        }
    }
}
