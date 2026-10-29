<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

/**
 * The effective per-tenant evaluation criteria (19-procurement-domain.md §36,
 * §205; ADR-175 D-B). A pure value object — the four §36 dimension weights, the
 * four "mandatory" flags, an optional pass threshold, and the optional §23
 * value threshold above which evaluation is required.
 *
 * `EvaluationSettingsReader::forTenant()` returns the persisted row's values,
 * or {@see defaults()} when the tenant has never configured anything — so an
 * unconfigured tenant sees EXACTLY the Proc.2b behaviour.
 *
 * @phpstan-type Dimension 'commercial'|'technical'|'quality'|'delivery'
 */
final readonly class EvaluationCriteria
{
    public function __construct(
        public int $commercialWeight,
        public int $technicalWeight,
        public int $qualityWeight,
        public int $deliveryWeight,
        public bool $commercialRequired,
        public bool $technicalRequired,
        public bool $qualityRequired,
        public bool $deliveryRequired,
        public ?int $passThreshold,
        public ?string $requiredAbove,
    ) {}

    /** The Proc.2b shipped constants: 40 / 30 / 15 / 15, all mandatory, no thresholds. */
    public static function defaults(): self
    {
        return new self(
            commercialWeight: 40,
            technicalWeight: 30,
            qualityWeight: 15,
            deliveryWeight: 15,
            commercialRequired: true,
            technicalRequired: true,
            qualityRequired: true,
            deliveryRequired: true,
            passThreshold: null,
            requiredAbove: null,
        );
    }

    /**
     * @return array{commercial: int, technical: int, quality: int, delivery: int}
     */
    public function weights(): array
    {
        return [
            'commercial' => $this->commercialWeight,
            'technical' => $this->technicalWeight,
            'quality' => $this->qualityWeight,
            'delivery' => $this->deliveryWeight,
        ];
    }

    /**
     * The dimensions an evaluator MUST score before `SubmitEvaluation`.
     *
     * @return list<string>
     */
    public function mandatoryDimensions(): array
    {
        $mandatory = [];
        if ($this->commercialRequired) {
            $mandatory[] = 'commercial';
        }
        if ($this->technicalRequired) {
            $mandatory[] = 'technical';
        }
        if ($this->qualityRequired) {
            $mandatory[] = 'quality';
        }
        if ($this->deliveryRequired) {
            $mandatory[] = 'delivery';
        }

        return $mandatory;
    }
}
