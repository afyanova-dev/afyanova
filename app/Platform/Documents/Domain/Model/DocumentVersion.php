<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Model;

use App\Platform\Documents\Domain\Enum\DocumentStatus;
use App\Platform\Documents\Domain\Exception\DocumentUnderDisposalException;
use App\Platform\Documents\Domain\Exception\DocumentVersionDisposedException;
use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DocumentVersion entity.
 *
 * @authority custodial (PLATFORM SERVICE)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_id
 * @property int $version_no
 * @property string|null $storage_key
 * @property string $mime_type
 * @property int $byte_size
 * @property string $content_hash
 * @property string|null $template_id
 * @property string|null $template_version
 * @property array<string, mixed>|null $generation_payload
 * @property string|null $generation_context_hash
 * @property string|null $derived_from_version_id
 * @property string|null $redaction_reason
 * @property string|null $redacted_by
 * @property DocumentStatus $status
 * @property string $created_by
 * @property CarbonImmutable|null $disposed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DocumentVersion extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'doc_document_versions';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'document_id',
        'version_no',
        'storage_key',
        'mime_type',
        'byte_size',
        'content_hash',
        'template_id',
        'template_version',
        'generation_payload',
        'generation_context_hash',
        'derived_from_version_id',
        'redaction_reason',
        'redacted_by',
        'status',
        'created_by',
        'disposed_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'version_no' => 'integer',
        'byte_size' => 'integer',
        'generation_payload' => 'array',
        'status' => DocumentStatus::class,
        'disposed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @param  array<string, mixed>|null  $generationPayload
     */
    public static function createVersion(
        string $tenantId,
        string $documentId,
        int $versionNo,
        string $mimeType,
        int $byteSize,
        string $contentHash,
        string $createdBy,
        DocumentStatus $status,
        ?string $storageKey = null,
        ?string $templateId = null,
        ?string $templateVersion = null,
        ?array $generationPayload = null,
        ?string $generationContextHash = null,
        ?string $derivedFromVersionId = null,
        ?string $redactionReason = null,
        ?string $redactedBy = null,
    ): self {
        $version = new self;
        $version->tenant_id = $tenantId;
        $version->document_id = $documentId;
        $version->version_no = $versionNo;
        $version->mime_type = $mimeType;
        $version->byte_size = $byteSize;
        $version->content_hash = $contentHash;
        $version->created_by = $createdBy;
        $version->status = $status;
        $version->storage_key = $storageKey;
        $version->template_id = $templateId;
        $version->template_version = $templateVersion;
        $version->generation_payload = $generationPayload;
        $version->generation_context_hash = $generationContextHash;
        $version->derived_from_version_id = $derivedFromVersionId;
        $version->redaction_reason = $redactionReason;
        $version->redacted_by = $redactedBy;

        return $version;
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /**
     * @return HasMany<DocumentSignature, $this>
     */
    public function signatures(): HasMany
    {
        return $this->hasMany(DocumentSignature::class, 'document_version_id');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function derivedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'derived_from_version_id');
    }

    public function markScanning(): void
    {
        $this->status = DocumentStatus::SCANNING;
    }

    public function markAvailable(): void
    {
        $this->status = DocumentStatus::AVAILABLE;
    }

    public function markQuarantined(): void
    {
        $this->status = DocumentStatus::QUARANTINED;
    }

    public function markPendingDisposal(): void
    {
        if ($this->status === DocumentStatus::DISPOSED) {
            throw new DocumentVersionDisposedException("Version {$this->id} has already been disposed.");
        }

        $this->status = DocumentStatus::PENDING_DISPOSAL;
    }

    public function markDisposalFailed(): void
    {
        $this->status = DocumentStatus::DISPOSAL_FAILED;
    }

    public function markDisposed(CarbonImmutable $disposedAt): void
    {
        $this->status = DocumentStatus::DISPOSED;
        $this->disposed_at = $disposedAt;
        $this->storage_key = null; // Physical bytes destroyed
    }

    public function canBeAccessed(): bool
    {
        return $this->status->canBeAccessed();
    }

    public function assertAccessible(): void
    {
        if ($this->status === DocumentStatus::DISPOSED) {
            throw new DocumentVersionDisposedException("Document version {$this->id} has been physically destroyed.");
        }

        if ($this->status === DocumentStatus::PENDING_DISPOSAL) {
            throw new DocumentUnderDisposalException("Document version {$this->id} is pending physical disposal.");
        }
    }
}
