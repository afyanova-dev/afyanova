<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Infrastructure\Reader;

use Illuminate\Support\Facades\DB;

final class AccountDirectoryReader
{
    /**
     * @param  list<string>  $candidateUserIds
     * @param  list<string>  $excludedUserIds
     * @return list<array{id: string, name: string, email: string, status: string, emailVerified: bool}>
     */
    public function searchFacilityAccounts(
        string $tenantId,
        string $pattern,
        array $candidateUserIds,
        array $excludedUserIds,
    ): array {
        if ($candidateUserIds === []) {
            return [];
        }

        return array_values(DB::table('users')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $candidateUserIds)
            ->whereNotIn('id', $excludedUserIds)
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(name) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(email) LIKE ?', [$pattern]))
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'email', 'status', 'email_verified_at'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'status' => (string) $row->status,
                'emailVerified' => $row->email_verified_at !== null,
            ])->all());
    }

    /** @return array{id: string, name: string, email: string, status: string, emailVerified: bool, createdAt: string}|null */
    public function tenantAccount(string $tenantId, string $userId): ?array
    {
        $row = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->where('id', $userId)
            ->first(['id', 'name', 'email', 'status', 'email_verified_at', 'created_at']);
        if ($row === null) {
            return null;
        }

        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'email' => (string) $row->email,
            'status' => (string) $row->status,
            'emailVerified' => $row->email_verified_at !== null,
            'createdAt' => (string) $row->created_at,
        ];
    }

    public function activeVerifiedAccountIdByEmail(string $tenantId, string $email): ?string
    {
        $id = DB::table('users')
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])
            ->where('status', 'ACTIVE')
            ->whereNotNull('email_verified_at')
            ->value('id');

        return is_string($id) ? $id : null;
    }

    /** @return list<string> */
    public function facilityInvitationUserIds(string $tenantId, string $facilityId): array
    {
        return array_values(DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->pluck('user_id')
            ->map(static fn (mixed $userId): string => (string) $userId)
            ->all());
    }

    public function facilityInvitationExists(string $tenantId, string $facilityId, string $userId): bool
    {
        return DB::table('aut_admin_bootstrap_tokens')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('user_id', $userId)
            ->exists();
    }
}
