<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\Model;

use App\Platform\Reporting\Domain\ValueObject\ReportCategory;
use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property ReportCategory $category
 * @property string|null $description
 * @property array<string>|null $source_projections
 * @property array<string, mixed> $schema_definition
 * @property bool $is_curated
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DataMart extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_data_marts';

    protected $fillable = [
        'id',
        'tenant_id',
        'code',
        'name',
        'category',
        'description',
        'source_projections',
        'schema_definition',
        'is_curated',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'category' => ReportCategory::class,
            'source_projections' => 'array',
            'schema_definition' => 'array',
            'is_curated' => 'boolean',
        ];
    }
}
