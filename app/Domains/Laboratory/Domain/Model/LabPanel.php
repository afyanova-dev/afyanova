<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Database\Factories\LabPanelFactory;
use App\Shared\Domain\AggregateRoot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use InvalidArgumentException;

/**
 * One laboratory panel — a named bundle of tests (14-laboratory-domain.md
 * §14–§15; ADR-162 D-C). The `LabTestDefinition` catalog precedent: tenant-scoped,
 * mutable, integer-versioned reference data authored ONLY by the
 * `laboratory:define-panel` console command. Members on `lab_panel_members`.
 *
 * @domain      Laboratory
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    none — tenant-wide reference data (ADR-162 D-C)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $category
 * @property string|null $description
 * @property bool $active
 * @property int $version
 */
final class LabPanel extends AggregateRoot
{
    /** @use HasFactory<LabPanelFactory> */
    use HasFactory;

    private const MAX_CODE_LENGTH = 100;

    private const MAX_NAME_LENGTH = 200;

    protected $table = 'lab_panels';

    /** @var list<string> */
    protected $fillable = ['id', 'tenant_id', 'code', 'name', 'category', 'description', 'active', 'version'];

    protected static function newFactory(): LabPanelFactory
    {
        return LabPanelFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public static function define(
        string $id,
        string $tenantId,
        string $code,
        string $name,
        ?string $category = null,
        ?string $description = null,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'code' => self::assert($code, self::MAX_CODE_LENGTH, 'code'),
            'name' => self::assert($name, self::MAX_NAME_LENGTH, 'name'),
            'category' => self::blankToNull($category),
            'description' => self::blankToNull($description),
            'active' => true,
            'version' => 1,
        ]);
    }

    public function revise(?string $name = null, ?string $category = null, ?string $description = null, ?bool $active = null): void
    {
        if ($name !== null) {
            $this->name = self::assert($name, self::MAX_NAME_LENGTH, 'name');
        }
        if ($category !== null) {
            $this->category = self::blankToNull($category);
        }
        if ($description !== null) {
            $this->description = self::blankToNull($description);
        }
        if ($active !== null) {
            $this->active = $active;
        }

        $this->version = (int) $this->version + 1;
    }

    private static function assert(string $value, int $max, string $field): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException("LabPanel::\${$field} must not be blank (ADR-162 D-C).");
        }

        if (mb_strlen($trimmed) > $max) {
            throw new InvalidArgumentException("LabPanel::\${$field} must be at most {$max} characters (ADR-162 D-C).");
        }

        return $trimmed;
    }

    private static function blankToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
