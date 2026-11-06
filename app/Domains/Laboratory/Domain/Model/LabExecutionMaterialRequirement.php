<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LabExecutionMaterialRequirement extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use HasVersionColumn;

    protected $table = 'lab_execution_material_requirements';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'execution_id',
        'method_requirement_id',
        'method_version',
        'inventory_item_id',
        'item_code_snapshot',
        'item_name_snapshot',
        'item_uom_snapshot',
        'item_type_snapshot',
        'item_category_snapshot',
        'requirement_role',
        'required',
        'usage_scope',
        'quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method_version' => 'integer',
            'required' => 'boolean',
            'quantity' => 'decimal:4',
            'version' => 'integer',
        ];
    }
}
