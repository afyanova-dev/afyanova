<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Model;

use App\Platform\Documents\Domain\Enum\SignatureMethod;
use App\Platform\Documents\Domain\Enum\SignerType;
use App\Shared\Domain\HasUuid;
use App\Shared\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentSignature entity.
 *
 * @authority custodial (PLATFORM SERVICE)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $document_version_id
 * @property string $signer_id
 * @property SignerType $signer_type
 * @property SignatureMethod $method
 * @property string $signature_digest
 * @property CarbonImmutable $signed_at
 * @property string|null $auth_event_ref
 * @property string|null $cert_fingerprint
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DocumentSignature extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'doc_signatures';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'document_version_id',
        'signer_id',
        'signer_type',
        'method',
        'signature_digest',
        'signed_at',
        'auth_event_ref',
        'cert_fingerprint',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'signer_type' => SignerType::class,
        'method' => SignatureMethod::class,
        'signed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function createSignature(
        string $tenantId,
        string $documentVersionId,
        string $signerId,
        SignerType $signerType,
        SignatureMethod $method,
        string $signatureDigest,
        CarbonImmutable $signedAt,
        ?string $authEventRef = null,
        ?string $certFingerprint = null,
    ): self {
        $sig = new self;
        $sig->tenant_id = $tenantId;
        $sig->document_version_id = $documentVersionId;
        $sig->signer_id = $signerId;
        $sig->signer_type = $signerType;
        $sig->method = $method;
        $sig->signature_digest = $signatureDigest;
        $sig->signed_at = $signedAt;
        $sig->auth_event_ref = $authEventRef;
        $sig->cert_fingerprint = $certFingerprint;

        return $sig;
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }
}
