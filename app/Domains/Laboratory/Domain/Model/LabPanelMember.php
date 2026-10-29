<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Model;

use App\Domains\Laboratory\Database\Factories\LabPanelMemberFactory;
use App\Shared\Concurrency\HasVersionColumn;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One test in a laboratory panel (14-laboratory-domain.md §14; ADR-162 D-C).
 * Plain Eloquent child — NOT an AggregateRoot (the `lab_order_items`
 * precedent). `version` is shape/conformance-only (stays 0).
 */
class LabPanelMember extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<LabPanelMemberFactory> */
    use HasFactory;

    use HasUuids;
    use HasVersionColumn;

    protected $table = 'lab_panel_members';

    /** @var list<string> */
    protected $fillable = ['id', 'tenant_id', 'panel_id', 'test_definition_id', 'sequence', 'version'];

    protected static function newFactory(): LabPanelMemberFactory
    {
        return LabPanelMemberFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'version' => 'integer'];
    }
}
