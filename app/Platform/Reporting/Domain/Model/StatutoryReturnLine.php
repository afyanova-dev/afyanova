<?php

declare(strict_types=1);

namespace App\Platform\Reporting\Domain\Model;

use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $return_id
 * @property string $data_element_code
 * @property string $category_option_combo
 * @property float $value
 * @property string|null $comment
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class StatutoryReturnLine extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'rpt_statutory_return_lines';

    protected $fillable = [
        'id',
        'tenant_id',
        'return_id',
        'data_element_code',
        'category_option_combo',
        'value',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'float',
        ];
    }

    /**
     * @return BelongsTo<StatutoryReturn, $this>
     */
    public function statutoryReturn(): BelongsTo
    {
        return $this->belongsTo(StatutoryReturn::class, 'return_id');
    }
}
