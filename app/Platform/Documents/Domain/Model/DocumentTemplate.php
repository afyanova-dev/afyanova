<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Model;

use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * DocumentTemplate entity.
 *
 * @authority custodial (PLATFORM SERVICE)
 *
 * @property string $id
 * @property string $tenant_id
 * @property DocumentType $doc_type
 * @property string $name
 * @property string $engine
 * @property string $layout
 * @property array<string, mixed>|null $variables_schema
 * @property string $version
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DocumentTemplate extends Model
{
    use BelongsToTenant;

    protected $table = 'doc_templates';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'doc_type',
        'name',
        'engine',
        'layout',
        'variables_schema',
        'version',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'doc_type' => DocumentType::class,
        'variables_schema' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @param  array<string, mixed>|null  $variablesSchema
     */
    public static function createTemplate(
        string $tenantId,
        string $id,
        DocumentType $docType,
        string $name,
        string $layout,
        string $version = '1.0',
        string $engine = 'blade',
        ?array $variablesSchema = null,
        bool $isActive = true,
    ): self {
        $tpl = new self;
        $tpl->tenant_id = $tenantId;
        $tpl->id = $id;
        $tpl->doc_type = $docType;
        $tpl->name = $name;
        $tpl->layout = $layout;
        $tpl->version = $version;
        $tpl->engine = $engine;
        $tpl->variables_schema = $variablesSchema;
        $tpl->is_active = $isActive;

        return $tpl;
    }
}
