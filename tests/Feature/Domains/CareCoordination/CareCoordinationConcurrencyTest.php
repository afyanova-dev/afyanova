<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('concurrent modification on Referral aggregate throws ConcurrencyConflict', function () {
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();

    $referral = TenantContextScope::run($tenantId, null, fn () => Referral::factory()
        ->forTenant($tenantId)
        ->atFacility($facilityId)
        ->forPatient($patientId)
        ->create(['status' => Referral::STATUS_DRAFT]));

    TenantContextScope::run($tenantId, null, function () use ($referral) {
        // Instance A loads version 0
        $instanceA = Referral::find($referral->id);
        // Instance B loads version 0
        $instanceB = Referral::find($referral->id);

        expect($instanceA)->not->toBeNull()
            ->and($instanceB)->not->toBeNull()
            ->and((int) $instanceA->version)->toBe(0)
            ->and((int) $instanceB->version)->toBe(0);

        // Instance A transitions DRAFT -> SENT -> bumps version to 1
        $instanceA->send();
        $instanceA->save();
        expect((int) $instanceA->version)->toBe(1);

        // Instance B attempts to transition DRAFT -> CANCELLED from stale version 0
        $instanceB->cancel();
        $instanceB->save();
    });
})->throws(ConcurrencyConflict::class);

test('concurrent modification on ReferralFeedback aggregate throws ConcurrencyConflict', function () {
    $tenantId = (string) Str::uuid7();
    $facilityId = (string) Str::uuid7();
    $referralId = (string) Str::uuid7();

    $feedback = TenantContextScope::run($tenantId, null, fn () => ReferralFeedback::factory()
        ->forTenant($tenantId)
        ->atFacility($facilityId)
        ->forReferral($referralId)
        ->create(['status' => ReferralFeedback::STATUS_PARTIAL, 'kind' => 'INTERIM']));

    TenantContextScope::run($tenantId, null, function () use ($feedback) {
        // Instance A loads version 0
        $instanceA = ReferralFeedback::find($feedback->id);
        // Instance B loads version 0
        $instanceB = ReferralFeedback::find($feedback->id);

        expect($instanceA)->not->toBeNull()
            ->and($instanceB)->not->toBeNull()
            ->and((int) $instanceA->version)->toBe(0)
            ->and((int) $instanceB->version)->toBe(0);

        // Instance A updates feedback -> bumps version to 1
        $instanceA->complete();
        $instanceA->save();
        expect((int) $instanceA->version)->toBe(1);

        // Instance B attempts to markNotProvided from stale version 0
        $instanceB->markNotProvided();
        $instanceB->save();
    });
})->throws(ConcurrencyConflict::class);
