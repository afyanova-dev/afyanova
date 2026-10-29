<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain\Model;

use App\Domains\Procurement\Database\Factories\EvaluationSettingsFactory;
use App\Domains\Procurement\Domain\EvaluationCriteria;
use App\Domains\Procurement\Domain\Exception\EvaluationSettingsInvalid;
use App\Domains\Procurement\Domain\Money;
use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A tenant's quotation-evaluation configuration (19-procurement-domain.md §23,
 * §36, §205; ADR-175 D-B).
 *
 * ONE row per tenant. Tenant-scoped, integer-versioned reference data — NOT the
 * ADR-132 record shape (the `ProcedureDefinition` / ADR-146 D-D precedent).
 * Authored ONLY via the `procurement:configure-evaluation` console command —
 * `initialise()` seeds the Proc.2b defaults, `revise()` patches + bumps
 * `version` in place. A tenant with NO row falls back to
 * {@see EvaluationCriteria::defaults()}.
 *
 * @domain      Procurement
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none — tenant-wide reference data (ADR-175 D-B)
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $commercial_weight
 * @property int $technical_weight
 * @property int $quality_weight
 * @property int $delivery_weight
 * @property bool $commercial_required
 * @property bool $technical_required
 * @property bool $quality_required
 * @property bool $delivery_required
 * @property int|null $pass_threshold
 * @property string|null $evaluation_required_above
 * @property int $version
 */
final class EvaluationSettings extends AggregateRoot
{
    /** @use HasFactory<EvaluationSettingsFactory> */
    use HasFactory;

    protected $table = 'pcr_evaluation_settings';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'commercial_weight', 'technical_weight', 'quality_weight', 'delivery_weight',
        'commercial_required', 'technical_required', 'quality_required', 'delivery_required',
        'pass_threshold', 'evaluation_required_above',
        'version',
    ];

    protected static function newFactory(): EvaluationSettingsFactory
    {
        return EvaluationSettingsFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commercial_weight' => 'integer',
            'technical_weight' => 'integer',
            'quality_weight' => 'integer',
            'delivery_weight' => 'integer',
            'commercial_required' => 'boolean',
            'technical_required' => 'boolean',
            'quality_required' => 'boolean',
            'delivery_required' => 'boolean',
            'pass_threshold' => 'integer',
            'version' => 'integer',
        ];
    }

    public static function initialise(string $id, string $tenantId): self
    {
        $defaults = EvaluationCriteria::defaults();

        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'commercial_weight' => $defaults->commercialWeight,
            'technical_weight' => $defaults->technicalWeight,
            'quality_weight' => $defaults->qualityWeight,
            'delivery_weight' => $defaults->deliveryWeight,
            'commercial_required' => $defaults->commercialRequired,
            'technical_required' => $defaults->technicalRequired,
            'quality_required' => $defaults->qualityRequired,
            'delivery_required' => $defaults->deliveryRequired,
            'pass_threshold' => null,
            'evaluation_required_above' => null,
            'version' => 1,
        ]);
    }

    /**
     * Patch the settings in place (the console write path ONLY). `null` for a
     * parameter leaves the current value unchanged; a `pass_threshold` /
     * `evaluationRequiredAbove` of `''` clears it. Bumps `version` and
     * re-validates every invariant.
     *
     * @param  array{commercial?: int, technical?: int, quality?: int, delivery?: int}  $weights
     * @param  array{commercial?: bool, technical?: bool, quality?: bool, delivery?: bool}  $mandatory
     */
    public function revise(array $weights = [], array $mandatory = [], ?string $passThreshold = null, ?string $evaluationRequiredAbove = null): void
    {
        foreach (['commercial', 'technical', 'quality', 'delivery'] as $dimension) {
            if (array_key_exists($dimension, $weights)) {
                $this->{$dimension.'_weight'} = $weights[$dimension];
            }
            if (array_key_exists($dimension, $mandatory)) {
                $this->{$dimension.'_required'} = $mandatory[$dimension];
            }
        }

        if ($passThreshold !== null) {
            $this->pass_threshold = trim($passThreshold) === '' ? null : (int) $passThreshold;
        }

        if ($evaluationRequiredAbove !== null) {
            $this->evaluation_required_above = trim($evaluationRequiredAbove) === ''
                ? null
                : Money::of($evaluationRequiredAbove);
        }

        $this->assertValid();

        $this->version = (int) $this->version + 1;
    }

    public function toCriteria(): EvaluationCriteria
    {
        return new EvaluationCriteria(
            commercialWeight: (int) $this->commercial_weight,
            technicalWeight: (int) $this->technical_weight,
            qualityWeight: (int) $this->quality_weight,
            deliveryWeight: (int) $this->delivery_weight,
            commercialRequired: (bool) $this->commercial_required,
            technicalRequired: (bool) $this->technical_required,
            qualityRequired: (bool) $this->quality_required,
            deliveryRequired: (bool) $this->delivery_required,
            passThreshold: $this->pass_threshold,
            requiredAbove: $this->evaluation_required_above !== null ? (string) $this->evaluation_required_above : null,
        );
    }

    private function assertValid(): void
    {
        $weights = [
            'commercial' => (int) $this->commercial_weight,
            'technical' => (int) $this->technical_weight,
            'quality' => (int) $this->quality_weight,
            'delivery' => (int) $this->delivery_weight,
        ];

        foreach ($weights as $dimension => $weight) {
            if ($weight < 0 || $weight > 100) {
                throw EvaluationSettingsInvalid::weightOutOfRange($dimension, $weight);
            }
        }

        if (array_sum($weights) <= 0) {
            throw EvaluationSettingsInvalid::noPositiveWeight();
        }

        if (! $this->commercial_required && ! $this->technical_required
            && ! $this->quality_required && ! $this->delivery_required) {
            throw EvaluationSettingsInvalid::noMandatoryDimension();
        }

        if ($this->pass_threshold !== null && ($this->pass_threshold < 0 || $this->pass_threshold > 100)) {
            throw EvaluationSettingsInvalid::passThresholdOutOfRange((int) $this->pass_threshold);
        }

        if ($this->evaluation_required_above !== null && Money::isNegative((string) $this->evaluation_required_above)) {
            throw EvaluationSettingsInvalid::requiredAboveNegative((string) $this->evaluation_required_above);
        }
    }
}
