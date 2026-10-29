<?php

declare(strict_types=1);

namespace App\Shared\Audit;

use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use JsonException;
use stdClass;
use UnexpectedValueException;

final class PlatformAuditReader
{
    private const MAX_FETCH = 1000;

    /** @var list<string> */
    private const VISIBLE_ACTION_PREFIXES = [
        'admin.',
        'auth.credential.',
        'identity.',
        'organization.',
        'procedures.definition_',
        'procedures.protocol_approval_',
        'queue.configuration.',
        'platform.',
    ];

    /** @var list<string> */
    private const VISIBLE_METADATA_KEYS = [
        'assignment',
        'assignments',
        'sessions_revoked',
        'staff',
        'capability',
        'code',
        'count',
        'mapping_count',
        'permissions',
        'permissions_count',
        'permissions_truncated',
        'promotion_request',
        'role',
        'room_count',
        'scope',
        'staff_count',
        'is_staff',
        'state',
        'status',
        'tests',
        'version',
    ];

    /**
     * @return list<array{id: string, name: string, status: string}>
     */
    public function tenants(): array
    {
        return array_values(DB::table('shared_tenants')
            ->orderBy('name')
            ->get(['id', 'name', 'status'])
            ->map(static fn (object $tenant): array => [
                'id' => (string) $tenant->id,
                'name' => (string) $tenant->name,
                'status' => (string) $tenant->status,
            ])->all());
    }

    /**
     * @param  array{from: string, to: string, action: string, actor_id: string, target: string, tenant_id: string}  $filters
     * @return array{entries: list<array<string, mixed>>, total: int}
     */
    public function entries(string $actorId, array $filters, int $page, int $perPage): array
    {
        return PlatformAuditScope::run($actorId, function () use ($actorId, $filters, $page, $perPage): array {
            app(AuditLogger::class)->recordPlatform(
                action: 'platform.audit.viewed',
                actorId: $actorId,
                targetType: 'audit_log',
                reason: 'Platform governance audit log viewed.',
                metadata: [
                    'from' => $filters['from'],
                    'to' => $filters['to'],
                    'tenant_filter' => $filters['tenant_id'],
                    'action_filter' => $filters['action'],
                ],
            );

            $offset = ($page - 1) * $perPage;
            $fetchLimit = min($offset + $perPage, self::MAX_FETCH);
            $from = CarbonImmutable::parse($filters['from'])->startOfDay();
            $to = CarbonImmutable::parse($filters['to'])->endOfDay();
            $tenants = DB::table('shared_tenants')
                ->when($filters['tenant_id'] !== '', fn ($query) => $query->where('id', $filters['tenant_id']))
                ->orderBy('name')
                ->get(['id', 'name']);
            $entries = [];
            $total = 0;

            foreach ($tenants as $tenant) {
                $tenantRows = TenantContextScope::run((string) $tenant->id, null, function () use (
                    $tenant,
                    $filters,
                    $from,
                    $to,
                    $fetchLimit,
                ): array {
                    $query = $this->filteredQuery($filters, $from, $to)->whereNotNull('tenant_id');
                    $count = (clone $query)->count();
                    $rows = $query
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->limit($fetchLimit)
                        ->get([
                            'id',
                            'facility_id',
                            'actor_id',
                            'action',
                            'target_type',
                            'target_id',
                            'metadata',
                            'created_at',
                        ])
                        ->map(fn (object $row): array => $this->formatEntry($row, (string) $tenant->id, (string) $tenant->name))
                        ->all();

                    return ['count' => $count, 'rows' => $rows];
                });

                $total += $tenantRows['count'];
                array_push($entries, ...$tenantRows['rows']);
            }

            if ($filters['tenant_id'] === '') {
                $platformQuery = $this->filteredQuery($filters, $from, $to)->whereNull('tenant_id');
                $total += (clone $platformQuery)->count();
                $platformRows = $platformQuery
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit($fetchLimit)
                    ->get([
                        'id',
                        'facility_id',
                        'actor_id',
                        'action',
                        'target_type',
                        'target_id',
                        'metadata',
                        'created_at',
                    ])
                    ->map(fn (object $row): array => $this->formatEntry($row, null, 'Platform'))
                    ->all();
                array_push($entries, ...$platformRows);
            }

            usort($entries, static fn (array $left, array $right): int => [$right['createdAt'], $right['id']] <=> [$left['createdAt'], $left['id']]);

            return [
                'entries' => array_slice($entries, $offset, $perPage),
                'total' => $total,
            ];
        });
    }

    /**
     * @param  array{from: string, to: string, action: string, actor_id: string, target: string, tenant_id: string}  $filters
     */
    private function filteredQuery(array $filters, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('shared_audit_log')
            ->where(function ($query): void {
                foreach (self::VISIBLE_ACTION_PREFIXES as $prefix) {
                    $query->orWhere('action', 'like', $prefix.'%');
                }
            })
            ->whereBetween('created_at', [$from, $to])
            ->when($filters['action'] !== '', fn ($query) => $query->where('action', 'like', '%'.$filters['action'].'%'))
            ->when($filters['actor_id'] !== '', fn ($query) => $query->where('actor_id', $filters['actor_id']))
            ->when($filters['target'] !== '', function ($query) use ($filters): void {
                $query->where(function ($targetQuery) use ($filters): void {
                    $targetQuery->where('target_type', 'like', '%'.$filters['target'].'%')
                        ->orWhere('target_id', 'like', '%'.$filters['target'].'%');
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEntry(stdClass $row, ?string $tenantId, string $tenantName): array
    {
        return [
            'id' => (string) $row->id,
            'tenantId' => $tenantId,
            'tenantName' => $tenantName,
            'facilityId' => $row->facility_id === null ? null : (string) $row->facility_id,
            'actorId' => $row->actor_id === null ? null : (string) $row->actor_id,
            'action' => (string) $row->action,
            'targetType' => $row->target_type === null ? null : (string) $row->target_type,
            'targetId' => $row->target_id === null ? null : (string) $row->target_id,
            'metadata' => $this->safeMetadata($row->metadata),
            'createdAt' => CarbonImmutable::parse($row->created_at)->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string|int|float|bool|list<string>>
     */
    private function safeMetadata(mixed $metadata): array
    {
        try {
            $decoded = is_string($metadata)
                ? json_decode($metadata, true, 512, JSON_THROW_ON_ERROR)
                : $metadata;
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Audit metadata is not valid JSON.', previous: $exception);
        }

        if (! is_array($decoded)) {
            return [];
        }

        $safe = [];
        foreach (self::VISIBLE_METADATA_KEYS as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 160);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $safe[$key] = $value;
            } elseif (($key === 'permissions' || $key === 'assignments') && is_array($value)) {
                $safe[$key] = array_values(array_filter(
                    array_slice($value, 0, 100),
                    static fn (mixed $item): bool => is_string($item),
                ));
            }
        }

        return $safe;
    }
}
