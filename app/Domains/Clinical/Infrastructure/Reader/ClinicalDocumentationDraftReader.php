<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Reads one author's documentation draft. Expired or unreadable payloads are
 * deleted and treated as absent; a draft is never surfaced as a clinical
 * record.
 */
final class ClinicalDocumentationDraftReader
{
    /**
     * @return array{values: array<string, mixed>, savedAt: string, expiresAt: string}|null
     */
    public function find(
        string $tenantId,
        string $facilityId,
        string $userId,
        string $encounterId,
        string $section,
    ): ?array {
        $draft = DB::table('clin_documentation_drafts')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('user_id', $userId)
            ->where('encounter_id', $encounterId)
            ->where('section', $section)
            ->first(['id', 'payload', 'expires_at', 'updated_at']);

        if ($draft === null) {
            return null;
        }

        if (now()->greaterThanOrEqualTo($draft->expires_at)) {
            DB::table('clin_documentation_drafts')->where('id', $draft->id)->delete();

            return null;
        }

        try {
            $values = json_decode(
                Crypt::decryptString((string) $draft->payload),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (DecryptException|JsonException) {
            DB::table('clin_documentation_drafts')->where('id', $draft->id)->delete();

            return null;
        }

        if (! is_array($values)) {
            DB::table('clin_documentation_drafts')->where('id', $draft->id)->delete();

            return null;
        }

        return [
            'values' => $values,
            'savedAt' => CarbonImmutable::parse((string) $draft->updated_at)->toIso8601String(),
            'expiresAt' => CarbonImmutable::parse((string) $draft->expires_at)->toIso8601String(),
        ];
    }
}
