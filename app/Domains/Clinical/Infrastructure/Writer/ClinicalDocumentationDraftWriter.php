<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Writer;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

/**
 * Persists one author's documentation draft. The encrypted payload is replaced
 * atomically for the same encounter section; no draft is ever written to a
 * clinical-record table.
 */
final class ClinicalDocumentationDraftWriter
{
    /**
     * @param  array<string, mixed>  $values
     * @return array{savedAt: string, expiresAt: string}
     *
     * @throws JsonException
     */
    public function save(
        string $tenantId,
        string $facilityId,
        string $userId,
        string $patientId,
        string $encounterId,
        string $section,
        array $values,
    ): array {
        $now = now();
        $expiresAt = $now->copy()->addDays(7);

        DB::table('clin_documentation_drafts')->upsert([[
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'user_id' => $userId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'section' => $section,
            'payload' => Crypt::encryptString(json_encode($values, JSON_THROW_ON_ERROR)),
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['tenant_id', 'facility_id', 'user_id', 'encounter_id', 'section'], [
            'patient_id',
            'payload',
            'expires_at',
            'updated_at',
        ]);

        return [
            'savedAt' => $now->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }

    public function delete(
        string $tenantId,
        string $facilityId,
        string $userId,
        string $encounterId,
        string $section,
    ): void {
        DB::table('clin_documentation_drafts')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('user_id', $userId)
            ->where('encounter_id', $encounterId)
            ->where('section', $section)
            ->delete();
    }
}
