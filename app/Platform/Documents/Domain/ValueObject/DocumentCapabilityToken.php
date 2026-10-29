<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\ValueObject;

use App\Platform\Documents\Domain\Enum\TokenAction;
use App\Platform\Documents\Domain\Exception\InvalidCapabilityTokenException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class DocumentCapabilityToken
{
    public function __construct(
        public string $jti,
        public string $tenantId,
        public string $documentId,
        public int $versionNo,
        public TokenAction $action,
        public CarbonImmutable $expiresAt,
    ) {}

    public static function mint(
        string $secret,
        string $tenantId,
        string $documentId,
        int $versionNo,
        TokenAction $action,
        CarbonImmutable $expiresAt,
        ?string $jti = null
    ): string {
        $jti ??= (string) Str::uuid();

        $payload = json_encode([
            'jti' => $jti,
            'tenant_id' => $tenantId,
            'document_id' => $documentId,
            'version_no' => $versionNo,
            'action' => $action->value,
            'expires_at' => $expiresAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR);

        $b64Payload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $b64Payload, $secret);
        $b64Sig = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return "{$b64Payload}.{$b64Sig}";
    }

    public function sign(string $secret): string
    {
        return self::mint(
            secret: $secret,
            tenantId: $this->tenantId,
            documentId: $this->documentId,
            versionNo: $this->versionNo,
            action: $this->action,
            expiresAt: $this->expiresAt,
            jti: $this->jti,
        );
    }

    public static function parseAndVerify(string $tokenString, string $secret): self
    {
        return self::verify($tokenString, $secret);
    }

    public static function verify(string $tokenString, string $secret): self
    {
        $parts = explode('.', $tokenString);
        if (count($parts) !== 2) {
            throw new InvalidCapabilityTokenException('Malformed capability token format.');
        }

        [$b64Payload, $b64Sig] = $parts;

        $rawSig = base64_decode(strtr($b64Sig, '-_', '+/'));
        $expectedSig = hash_hmac('sha256', $b64Payload, $secret);

        if (! hash_equals($expectedSig, $rawSig)) {
            throw new InvalidCapabilityTokenException('Invalid capability token signature.');
        }

        $rawPayload = base64_decode(strtr($b64Payload, '-_', '+/'));
        /** @var array{jti: string, tenant_id: string, document_id: string, version_no: int, action: string, expires_at: int} $data */
        $data = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);

        $expiresAt = CarbonImmutable::createFromTimestamp($data['expires_at']);
        if ($expiresAt->isPast()) {
            throw new InvalidCapabilityTokenException('Capability token has expired.');
        }

        $action = TokenAction::tryFrom($data['action'])
            ?? throw new InvalidCapabilityTokenException("Invalid action in capability token: {$data['action']}");

        return new self(
            jti: $data['jti'],
            tenantId: $data['tenant_id'],
            documentId: $data['document_id'],
            versionNo: (int) $data['version_no'],
            action: $action,
            expiresAt: $expiresAt,
        );
    }
}
