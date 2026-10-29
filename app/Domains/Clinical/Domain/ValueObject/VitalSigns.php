<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The nine ratified core vital-sign measurements of one observation set
 * (12-clinical-domain.md §19–§20; ADR-138). Every field is optional, but an
 * observation MUST carry at least one non-null measurement (D2).
 *
 * FIXED CANONICAL STORAGE UNITS (D3) — there is no unit column and no
 * conversion: temperature °C, heart rate bpm, respiratory rate breaths/min,
 * systolic / diastolic BP mmHg, SpO2 %, weight kg, height cm, pain score 0–10
 * NRS. `temperature`, `weight` and `height` are rounded to one decimal place
 * on construction (the storage precision — `decimal(4,1)` / `decimal(5,1)`);
 * the other six are integers (D4).
 *
 * {@see of()} rejects ONLY structurally-impossible values, against the
 * canonical STRUCTURAL SAFETY BOUNDS in {@see BOUNDS} — these are NOT clinical
 * normal ranges, and there is no abnormality detection or `abnormal` flag
 * (D5, `12` §139). BMI is derived, never modelled here.
 */
final readonly class VitalSigns
{
    /**
     * ADR-138 structural safety bounds: [min, max], inclusive. NOT clinical
     * normal ranges — only a value outside these is structurally impossible.
     *
     * @var array<string, array{0: int|float, 1: int|float}>
     */
    public const BOUNDS = [
        'temperature' => [20.0, 45.0],
        'heartRate' => [0, 350],
        'respiratoryRate' => [0, 150],
        'systolicBp' => [0, 300],
        'diastolicBp' => [0, 250],
        'oxygenSaturation' => [0, 100],
        'weight' => [0, 500],
        'height' => [0, 300],
        'painScore' => [0, 10],
    ];

    private function __construct(
        public ?float $temperature,
        public ?int $heartRate,
        public ?int $respiratoryRate,
        public ?int $systolicBp,
        public ?int $diastolicBp,
        public ?int $oxygenSaturation,
        public ?float $weight,
        public ?float $height,
        public ?int $painScore,
    ) {}

    public static function of(
        ?float $temperature = null,
        ?int $heartRate = null,
        ?int $respiratoryRate = null,
        ?int $systolicBp = null,
        ?int $diastolicBp = null,
        ?int $oxygenSaturation = null,
        ?float $weight = null,
        ?float $height = null,
        ?int $painScore = null,
    ): self {
        $temperature = $temperature === null ? null : round($temperature, 1);
        $weight = $weight === null ? null : round($weight, 1);
        $height = $height === null ? null : round($height, 1);

        $values = [
            'temperature' => $temperature,
            'heartRate' => $heartRate,
            'respiratoryRate' => $respiratoryRate,
            'systolicBp' => $systolicBp,
            'diastolicBp' => $diastolicBp,
            'oxygenSaturation' => $oxygenSaturation,
            'weight' => $weight,
            'height' => $height,
            'painScore' => $painScore,
        ];

        if (array_filter($values, static fn ($v): bool => $v !== null) === []) {
            throw new InvalidArgumentException(
                'VitalSigns must carry at least one non-null core measurement.'
            );
        }

        foreach ($values as $name => $value) {
            if ($value === null) {
                continue;
            }

            [$min, $max] = self::BOUNDS[$name];

            if ($value < $min || $value > $max) {
                throw new InvalidArgumentException(sprintf(
                    'VitalSigns::%s value %s is outside the structural safety bounds [%s, %s].',
                    $name,
                    $value,
                    $min,
                    $max,
                ));
            }
        }

        return new self(
            $temperature, $heartRate, $respiratoryRate,
            $systolicBp, $diastolicBp, $oxygenSaturation,
            $weight, $height, $painScore,
        );
    }

    /**
     * The nine measurements keyed by their `clin_vital_sign_observations`
     * column names, for the aggregate to persist.
     *
     * @return array<string, float|int|null>
     */
    public function toColumns(): array
    {
        return [
            'temperature' => $this->temperature,
            'heart_rate' => $this->heartRate,
            'respiratory_rate' => $this->respiratoryRate,
            'systolic_bp' => $this->systolicBp,
            'diastolic_bp' => $this->diastolicBp,
            'oxygen_saturation' => $this->oxygenSaturation,
            'weight' => $this->weight,
            'height' => $this->height,
            'pain_score' => $this->painScore,
        ];
    }
}
