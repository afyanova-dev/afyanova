<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Controller;

use App\Models\User;
use App\Shared\Audit\PlatformAuditReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PlatformAuditController
{
    public function __invoke(Request $request, PlatformAuditReader $reader): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'tenant_id' => ['nullable', 'uuid', Rule::exists('shared_tenants', 'id')],
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'uuid'],
            'target' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:40'],
            'per_page' => ['nullable', 'integer', Rule::in([25])],
        ]);

        $to = CarbonImmutable::parse((string) ($validated['to'] ?? now()->toDateString()));
        $from = CarbonImmutable::parse((string) ($validated['from'] ?? $to->subDays(29)->toDateString()));
        if ($from->greaterThan($to) || $from->diffInDays($to) > 90) {
            throw ValidationException::withMessages([
                'from' => 'Choose a date range of no more than 90 days ending on or after the start date.',
            ]);
        }

        $filters = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'tenant_id' => (string) ($validated['tenant_id'] ?? ''),
            'action' => trim((string) ($validated['action'] ?? '')),
            'actor_id' => (string) ($validated['actor_id'] ?? ''),
            'target' => trim((string) ($validated['target'] ?? '')),
        ];
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 25);
        $result = $reader->entries((string) $user->getKey(), $filters, $page, $perPage);

        return Inertia::render('PlatformAdmin/Audit/Index', [
            ...$result,
            'tenants' => $reader->tenants(),
            'filters' => $filters,
            'currentPage' => $page,
            'lastPage' => min(40, max(1, (int) ceil($result['total'] / $perPage))),
            'resultLimit' => 1000,
            'perPage' => $perPage,
        ]);
    }
}
