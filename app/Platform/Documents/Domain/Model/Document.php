<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Model;

use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentKind;
use App\Platform\Documents\Domain\Enum\DocumentStatus;
use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Document aggregate root / entity.
 *
 * @authority custodial (PLATFORM SERVICE)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property DocumentKind $kind
 * @property DocumentType $doc_type
 * @property ConfidentialityLevel $confidentiality_level
 * @property string $source_domain
 * @property string $source_entity_ref
 * @property string|null $encounter_id
 * @property string|null $patient_id
 * @property string|null $current_version_id
 * @property DocumentStatus $status
 * @property string|null $superseded_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Document extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'doc_documents';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'kind',
        'doc_type',
        'confidentiality_level',
        'source_domain',
        'source_entity_ref',
        'encounter_id',
        'patient_id',
        'current_version_id',
        'status',
        'superseded_by',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => DocumentKind::class,
        'doc_type' => DocumentType::class,
        'confidentiality_level' => ConfidentialityLevel::class,
        'status' => DocumentStatus::class,
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function createDocument(
        string $tenantId,
        string $facilityId,
        DocumentKind $kind,
        DocumentType $docType,
        ConfidentialityLevel $confidentialityLevel,
        string $sourceDomain,
        string $sourceEntityRef,
        ?string $encounterId = null,
        ?string $patientId = null,
        DocumentStatus $status = DocumentStatus::AVAILABLE,
    ): self {
        $doc = new self;
        $doc->tenant_id = $tenantId;
        $doc->facility_id = $facilityId;
        $doc->kind = $kind;
        $doc->doc_type = $docType;
        $doc->confidentiality_level = $confidentialityLevel;
        $doc->source_domain = $sourceDomain;
        $doc->source_entity_ref = $sourceEntityRef;
        $doc->encounter_id = $encounterId;
        $doc->patient_id = $patientId;
        $doc->status = $status;

        return $doc;
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /**
     * @return HasMany<DocumentAccessGrant, $this>
     */
    public function accessGrants(): HasMany
    {
        return $this->hasMany(DocumentAccessGrant::class, 'document_id');
    }

    /**
     * @return HasMany<RetentionRequirement, $this>
     */
    public function retentionRequirements(): HasMany
    {
        return $this->hasMany(RetentionRequirement::class, 'document_id');
    }

    public function markSupersededBy(string $newDocumentId): void
    {
        $this->superseded_by = $newDocumentId;
    }

    public function setCurrentVersion(DocumentVersion $version): void
    {
        $this->current_version_id = $version->id;
        $this->status = $version->status;
    }

    public function updateStatus(DocumentStatus $status): void
    {
        $this->status = $status;
    }
}
