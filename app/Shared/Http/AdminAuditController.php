<?php

declare(strict_types=1);

namespace App\Shared\Http;

use App\Models\User;
use App\Shared\Authorization\OrganizationPermissionScope;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use JsonException;

final class AdminAuditController
{
    private const MAX_PER_PAGE = 100;

    private const MAX_PAGE = 10000;

    private const MAX_DATE_RANGE_DAYS = 90;

    /** @var list<string> */
    private const VISIBLE_ACTION_PREFIXES = [
        'admin.',
        'auth.credential.',
        'identity.',
        'organization.',
        'procedures.definition_',
        'procedures.protocol_approval_',
        'queue.configuration.',
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

    public function __invoke(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        OrganizationPermissionScope $organizationPermissions,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(
            $user->can('audit.events.read') || $organizationPermissions->allows($user, 'audit.events.read'),
            403,
            'Unauthorized.',
        );

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'uuid'],
            'target' => ['nullable', 'string', 'max:100'],
            'facility_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ]);

        $to = CarbonImmutable::parse((string) ($validated['to'] ?? now()->toDateString()));
        $from = CarbonImmutable::parse((string) ($validated['from'] ?? $to->subDays(29)->toDateString()));

        if ($from->greaterThan($to) || $from->diffInDays($to) > self::MAX_DATE_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'from' => 'Choose a date range of no more than 90 days ending on or after the start date.',
            ]);
        }

        $allowedFacilityIds = $scopeChecker->selectableFacilities($user);
        $facilities = DB::table('org_facilities')
            ->where('tenant_id', $context->tenantId())
            ->where('status', 'ACTIVE')
            ->whereIn('id', $allowedFacilityIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (object $facility): array => [
                'id' => (string) $facility->id,
                'name' => (string) $facility->name,
            ])
            ->all();

        $allowedOrganizationIds = DB::table('org_facilities')
            ->whereIn('id', $allowedFacilityIds)
            ->distinct()
            ->pluck('organization_id')
            ->all();

        $isOrganizationScoped = DB::table('idn_access_assignments as assignment')
            ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('idn_permission_role as permission_role', 'permission_role.role_id', '=', 'role.id')
            ->join('idn_permissions as permission', 'permission.id', '=', 'permission_role.permission_id')
            ->where('assignment.user_id', $user->getKey())
            ->where('assignment.status', 'ACTIVE')
            ->where('role.status', 'ACTIVE')
            ->where('role.scope_type', 'ORG')
            ->where('permission.key', 'audit.events.read')
            ->whereIn('assignment.organization_id', $allowedOrganizationIds)
            ->whereNull('assignment.facility_id')
            ->where(fn ($query) => $query->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>=', now()))
            ->exists();

        $requestedFacilityId = $validated['facility_id'] ?? null;
        if ($requestedFacilityId !== null && ! in_array($requestedFacilityId, $allowedFacilityIds, true)) {
            abort(403, 'You are not authorized to view audit events for this facility.');
        }

        $facilityFilter = null;
        if ($requestedFacilityId !== null) {
            $facilityFilter = [$requestedFacilityId];
        } elseif (! $isOrganizationScoped) {
            $activeFacilityId = $context->facilityId();
            if ($activeFacilityId !== null && in_array($activeFacilityId, $allowedFacilityIds, true)) {
                $facilityFilter = [$activeFacilityId];
            } elseif (count($allowedFacilityIds) === 1) {
                $facilityFilter = $allowedFacilityIds;
            } else {
                $facilityFilter = [];
            }
        }

        $query = DB::table('shared_audit_log')
            ->where('tenant_id', $context->tenantId())
            ->where(function ($query): void {
                foreach (self::VISIBLE_ACTION_PREFIXES as $prefix) {
                    $query->orWhere('action', 'like', $prefix.'%');
                }
            })
            ->whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()]);

        if ($facilityFilter !== null) {
            $query->whereIn('facility_id', $facilityFilter);
        }

        $action = trim((string) ($validated['action'] ?? ''));
        if ($action !== '') {
            $query->where('action', 'like', '%'.$action.'%');
        }

        if (isset($validated['actor_id'])) {
            $query->where('actor_id', $validated['actor_id']);
        }

        $target = trim((string) ($validated['target'] ?? ''));
        if ($target !== '') {
            $query->where(function ($query) use ($target): void {
                $query->where('target_type', 'like', '%'.$target.'%')
                    ->orWhere('target_id', 'like', '%'.$target.'%');
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = (int) ($validated['page'] ?? 1);
        $paginator = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, [
                'id',
                'facility_id',
                'actor_id',
                'action',
                'target_type',
                'target_id',
                'metadata',
                'created_at',
            ], 'page', $page);

        /** @var list<object{
         *     id: string,
         *     facility_id: string|null,
         *     actor_id: string|null,
         *     action: string,
         *     target_type: string|null,
         *     target_id: string|null,
         *     metadata: mixed,
         *     created_at: string
         * }> $auditRows
         */
        $auditRows = $paginator->items();

        return Inertia::render('Admin/Audit/Index', [
            'entries' => array_map(
                fn ($entry): array => [
                    'id' => (string) $entry->id,
                    'facilityId' => $entry->facility_id === null ? null : (string) $entry->facility_id,
                    'actorId' => $entry->actor_id === null ? null : (string) $entry->actor_id,
                    'action' => (string) $entry->action,
                    'targetType' => $entry->target_type === null ? null : (string) $entry->target_type,
                    'targetId' => $entry->target_id === null ? null : (string) $entry->target_id,
                    'metadata' => $this->safeMetadata($entry->metadata),
                    'createdAt' => CarbonImmutable::parse($entry->created_at)->toIso8601String(),
                ],
                $auditRows,
            ),
            'facilities' => $facilities,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'action' => $action,
                'actor_id' => (string) ($validated['actor_id'] ?? ''),
                'target' => $target,
                'facility_id' => (string) ($requestedFacilityId ?? ''),
            ],
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $perPage,
        ]);
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
            throw new \UnexpectedValueException('Audit metadata is not valid JSON.', previous: $exception);
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
                    static fn (mixed $permission): bool => is_string($permission),
                ));
            }
        }

        return $safe;
    }
}
