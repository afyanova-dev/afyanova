<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Domain\Exception\IllegalCareEpisodeEncounterTransition;
use App\Domains\CareCoordination\Domain\Exception\IllegalCareEpisodeTransition;
use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Domains\CareCoordination\Domain\Model\CareEpisodeEncounter;
use App\Domains\CareCoordination\Domain\Service\CareEpisodeLifecycle;

beforeEach(function () {
    $this->lifecycle = new CareEpisodeLifecycle;
});

test('assertCanClose allows only OPEN', function () {
    $this->lifecycle->assertCanClose(CareEpisode::STATUS_OPEN);

    foreach ([CareEpisode::STATUS_CLOSED, CareEpisode::STATUS_TRANSFERRED_OUT] as $status) {
        expect(fn () => $this->lifecycle->assertCanClose($status))
            ->toThrow(IllegalCareEpisodeTransition::class);
    }
});

test('assertCanTransferOut allows only OPEN', function () {
    $this->lifecycle->assertCanTransferOut(CareEpisode::STATUS_OPEN);

    foreach ([CareEpisode::STATUS_CLOSED, CareEpisode::STATUS_TRANSFERRED_OUT] as $status) {
        expect(fn () => $this->lifecycle->assertCanTransferOut($status))
            ->toThrow(IllegalCareEpisodeTransition::class);
    }
});

test('assertCanLinkEncounter allows only an OPEN episode', function () {
    $this->lifecycle->assertCanLinkEncounter(CareEpisode::STATUS_OPEN);

    foreach ([CareEpisode::STATUS_CLOSED, CareEpisode::STATUS_TRANSFERRED_OUT] as $status) {
        expect(fn () => $this->lifecycle->assertCanLinkEncounter($status))
            ->toThrow(IllegalCareEpisodeTransition::class);
    }
});

test('assertCanDetach allows only a LINKED link', function () {
    $this->lifecycle->assertCanDetach(CareEpisodeEncounter::STATUS_LINKED);

    expect(fn () => $this->lifecycle->assertCanDetach(CareEpisodeEncounter::STATUS_DETACHED))
        ->toThrow(IllegalCareEpisodeEncounterTransition::class);
});
