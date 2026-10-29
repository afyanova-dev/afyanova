<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Infrastructure;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read + write access to `aut_sessions` (docs/00-foundation/04-authentication.md
 * §22–§23). The one place the Authentication module touches that table from the
 * request path — controllers stay HTTP-only (IC §15).
 *
 * RLS scopes every query to the current tenant; callers additionally pass the
 * user id.
 */
final class Sessions
{
    /**
     * @return Collection<int, array{id: string, ip: string|null, userAgent: string|null, lastActiveAt: string|null, current: bool}>
     */
    public function activeFor(string $userId, string $currentSessionId): Collection
    {
        return DB::table('aut_sessions')
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->orderByDesc('last_active_at')
            ->get(['id', 'session_id', 'ip_address', 'user_agent', 'last_active_at'])
            ->map(static fn ($row): array => [
                'id' => (string) $row->id,
                'ip' => $row->ip_address === null ? null : (string) $row->ip_address,
                'userAgent' => $row->user_agent === null ? null : (string) $row->user_agent,
                'lastActiveAt' => $row->last_active_at === null ? null : (string) $row->last_active_at,
                'current' => $row->session_id === $currentSessionId,
            ])
            ->values();
    }

    public function revoke(string $userId, string $id): void
    {
        DB::table('aut_sessions')
            ->where('user_id', $userId)
            ->where('id', $id)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    public function facilityName(string $facilityId): ?string
    {
        $name = DB::table('org_facilities')->where('id', $facilityId)->value('name');

        return $name === null ? null : (string) $name;
    }
}
