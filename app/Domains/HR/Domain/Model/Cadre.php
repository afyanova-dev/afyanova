<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Domain\ValueObject\CadreCategory;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Professional cadre classification (25-hr-and-workforce-domain.md §4, §6; ADR-206).
 *
 * @domain      HR
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property CadreCategory $category
 * @property string|null $description
 * @property bool $is_active
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Cadre extends AggregateRoot
{
    protected $table = 'hr_cadres';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'category',
        'description',
        'is_active',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'category' => CadreCategory::class,
        'is_active' => 'boolean',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function create(
        string $tenantId,
        string $code,
        string $name,
        CadreCategory $category,
        ?string $description = null,
        bool $isActive = true,
    ): self {
        $cadre = new self;
        $cadre->tenant_id = $tenantId;
        $cadre->code = strtoupper(trim($code));
        $cadre->name = trim($name);
        $cadre->category = $category;
        $cadre->description = $description;
        $cadre->is_active = $isActive;

        return $cadre;
    }

    public function updateDetails(
        string $name,
        CadreCategory $category,
        ?string $description = null,
        bool $isActive = true,
    ): void {
        $this->name = trim($name);
        $this->category = $category;
        $this->description = $description;
        $this->is_active = $isActive;
    }

    /**
     * @return HasMany<Position, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(Position::class, 'cadre_id');
    }
}
