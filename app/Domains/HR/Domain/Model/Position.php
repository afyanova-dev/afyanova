<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Organizational job position referencing a Cadre (25-hr-and-workforce-domain.md §4, §6; ADR-206).
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
 * @property string $title
 * @property string $cadre_id
 * @property string|null $description
 * @property bool $is_active
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Position extends AggregateRoot
{
    protected $table = 'hr_positions';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'code',
        'title',
        'cadre_id',
        'description',
        'is_active',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function create(
        string $tenantId,
        string $code,
        string $title,
        string $cadreId,
        ?string $description = null,
        bool $isActive = true,
    ): self {
        $position = new self;
        $position->tenant_id = $tenantId;
        $position->code = strtoupper(trim($code));
        $position->title = trim($title);
        $position->cadre_id = $cadreId;
        $position->description = $description;
        $position->is_active = $isActive;

        return $position;
    }

    public function updateDetails(
        string $title,
        string $cadreId,
        ?string $description = null,
        bool $isActive = true,
    ): void {
        $this->title = trim($title);
        $this->cadre_id = $cadreId;
        $this->description = $description;
        $this->is_active = $isActive;
    }

    /**
     * @return BelongsTo<Cadre, $this>
     */
    public function cadre(): BelongsTo
    {
        return $this->belongsTo(Cadre::class, 'cadre_id');
    }
}
