<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Reader;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

final class RegistrationDraftReader
{
    /**
     * @return array<string, mixed>|null
     *
     * @throws JsonException
     */
    public function find(string $tenantId, string $facilityId, string $userId): ?array
    {
        $draft = DB::table('pat_registration_drafts')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('user_id', $userId)
            ->first(['id', 'payload', 'expires_at']);

        if ($draft === null) {
            return null;
        }

        if (now()->greaterThanOrEqualTo($draft->expires_at)) {
            DB::table('pat_registration_drafts')->where('id', $draft->id)->delete();

            return null;
        }

        $payload = json_decode(Crypt::decryptString((string) $draft->payload), true, flags: JSON_THROW_ON_ERROR);

        return is_array($payload) ? $payload : null;
    }
}
