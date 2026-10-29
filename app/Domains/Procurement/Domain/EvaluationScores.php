<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Domain\Model\QuotationEvaluation;

/**
 * The four §36 dimension scores of one evaluation, each possibly absent
 * (a DRAFT, or a dimension the tenant marked optional). A pure value object
 * (ADR-175 D-D).
 */
final readonly class EvaluationScores
{
    public function __construct(
        public ?int $commercial,
        public ?int $technical,
        public ?int $quality,
        public ?int $delivery,
    ) {}

    public static function fromEvaluation(QuotationEvaluation $evaluation): self
    {
        return new self(
            commercial: $evaluation->commercial_score,
            technical: $evaluation->technical_score,
            quality: $evaluation->quality_score,
            delivery: $evaluation->delivery_score,
        );
    }

    public function has(string $dimension): bool
    {
        return $this->get($dimension) !== null;
    }

    public function get(string $dimension): ?int
    {
        return match ($dimension) {
            'commercial' => $this->commercial,
            'technical' => $this->technical,
            'quality' => $this->quality,
            'delivery' => $this->delivery,
            default => null,
        };
    }

    /**
     * The dimensions that carry a score.
     *
     * @return list<string>
     */
    public function present(): array
    {
        $present = [];
        foreach (['commercial', 'technical', 'quality', 'delivery'] as $dimension) {
            if ($this->has($dimension)) {
                $present[] = $dimension;
            }
        }

        return $present;
    }
}
