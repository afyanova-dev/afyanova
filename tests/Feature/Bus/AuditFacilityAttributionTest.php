<?php

declare(strict_types=1);

namespace Tests\Feature\Bus;

use App\Domains\Organization\Domain\Model\Facility;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Audit\AuditFacilityAware;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;

final readonly class RecordFacilityAudit implements Auditable, AuditFacilityAware
{
    public function __construct(public ?string $facilityId) {}

    public function auditFacilityId(): ?string
    {
        return $this->facilityId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: $this->facilityId === null ? 'test.audit.organization_change' : 'test.audit.facility_change',
            targetType: 'facility',
            targetId: $this->facilityId,
        );
    }
}

final class RecordFacilityAuditHandler
{
    public function __invoke(RecordFacilityAudit $command): void {}
}

beforeEach(function (): void {
    CommandBus::flush();
    CommandBus::map([RecordFacilityAudit::class => RecordFacilityAuditHandler::class]);
});

afterEach(function (): void {
    CommandBus::flush();
});

it('attributes an audit entry to the target facility instead of the active facility', function (): void {
    ['tenant' => $tenant, 'organization' => $organization, 'facility' => $activeFacility] = makeTenantWithUser();
    $targetFacility = Facility::factory()
        ->forTenant($tenant->id, $organization->id)
        ->create();

    TenantContextScope::run($tenant->id, $activeFacility->id, fn () => app(CommandBus::class)
        ->dispatch(new RecordFacilityAudit($targetFacility->id)));

    TenantContextScope::run($tenant->id, null, function () use ($tenant, $targetFacility): void {
        expect(DB::table('shared_audit_log')
            ->where('tenant_id', $tenant->id)
            ->where('action', 'test.audit.facility_change')
            ->value('facility_id'))->toBe($targetFacility->id);
    });
});

it('preserves null facility attribution for an organization-level audit under an active facility context', function (): void {
    ['tenant' => $tenant, 'facility' => $activeFacility] = makeTenantWithUser();

    TenantContextScope::run($tenant->id, $activeFacility->id, fn () => app(CommandBus::class)
        ->dispatch(new RecordFacilityAudit(null)));

    TenantContextScope::run($tenant->id, null, function () use ($tenant): void {
        expect(DB::table('shared_audit_log')
            ->where('tenant_id', $tenant->id)
            ->where('action', 'test.audit.organization_change')
            ->value('facility_id'))->toBeNull();
    });
});
