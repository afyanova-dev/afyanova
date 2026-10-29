<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Writer;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final class RegistrationDraftWriter
{
    /**
     * @param  array<string, mixed>  $values
     * @return array{savedAt: string, expiresAt: string}
     *
     * @throws JsonException
     */
    public function save(string $tenantId, string $facilityId, string $userId, array $values): array
    {
        $now = now();
        $expiresAt = $now->copy()->addDays(7);

        DB::table('pat_registration_drafts')->upsert([[
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'user_id' => $userId,
            'payload' => Crypt::encryptString(json_encode($values, JSON_THROW_ON_ERROR)),
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]], ['tenant_id', 'facility_id', 'user_id'], ['payload', 'expires_at', 'updated_at']);

        return [
            'savedAt' => $now->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
    }

    public function delete(string $tenantId, string $facilityId, string $userId): void
    {
        DB::table('pat_registration_drafts')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('user_id', $userId)
            ->delete();
    }
}
