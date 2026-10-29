<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Domain\Exception\IllegalReferralFeedbackTransition;
use App\Domains\CareCoordination\Domain\Exception\IllegalReferralTransition;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Domains\CareCoordination\Domain\Service\ReferralLifecycle;

beforeEach(function () {
    $this->lifecycle = new ReferralLifecycle;
});

test('assertCanSend allows only DRAFT and rejects all other statuses', function () {
    $this->lifecycle->assertCanSend(Referral::STATUS_DRAFT);

    $invalidStatuses = [
        Referral::STATUS_SENT,
        Referral::STATUS_RECEIVED,
        Referral::STATUS_ACCEPTED,
        Referral::STATUS_COMPLETED,
        Referral::STATUS_REJECTED,
        Referral::STATUS_CANCELLED,
        Referral::STATUS_EXPIRED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanSend($status))
            ->toThrow(IllegalReferralTransition::class);
    }
});

test('assertCanReceive allows only SENT and rejects all other statuses', function () {
    $this->lifecycle->assertCanReceive(Referral::STATUS_SENT);

    $invalidStatuses = [
        Referral::STATUS_DRAFT,
        Referral::STATUS_RECEIVED,
        Referral::STATUS_ACCEPTED,
        Referral::STATUS_COMPLETED,
        Referral::STATUS_REJECTED,
        Referral::STATUS_CANCELLED,
        Referral::STATUS_EXPIRED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanReceive($status))
            ->toThrow(IllegalReferralTransition::class);
    }
});

test('assertCanAccept allows only RECEIVED and rejects all other statuses', function () {
    $this->lifecycle->assertCanAccept(Referral::STATUS_RECEIVED);

    $invalidStatuses = [
        Referral::STATUS_DRAFT,
        Referral::STATUS_SENT,
        Referral::STATUS_ACCEPTED,
        Referral::STATUS_COMPLETED,
        Referral::STATUS_REJECTED,
        Referral::STATUS_CANCELLED,
        Referral::STATUS_EXPIRED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanAccept($status))
            ->toThrow(IllegalReferralTransition::class);
    }
});

test('assertCanReject allows SENT and RECEIVED, rejects others', function () {
    $this->lifecycle->assertCanReject(Referral::STATUS_SENT);
    $this->lifecycle->assertCanReject(Referral::STATUS_RECEIVED);

    $invalidStatuses = [
        Referral::STATUS_DRAFT,
        Referral::STATUS_ACCEPTED,
        Referral::STATUS_COMPLETED,
        Referral::STATUS_REJECTED,
        Referral::STATUS_CANCELLED,
        Referral::STATUS_EXPIRED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanReject($status))
            ->toThrow(IllegalReferralTransition::class);
    }
});

test('assertCanComplete allows only ACCEPTED and rejects all other statuses', function () {
    $this->lifecycle->assertCanComplete(Referral::STATUS_ACCEPTED);

    $invalidStatuses = [
        Referral::STATUS_DRAFT,
        Referral::STATUS_SENT,
        Referral::STATUS_RECEIVED,
        Referral::STATUS_COMPLETED,
        Referral::STATUS_REJECTED,
        Referral::STATUS_CANCELLED,
        Referral::STATUS_EXPIRED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanComplete($status))
            ->toThrow(IllegalReferralTransition::class);
    }
});

test('assertCanCancel allows DRAFT, SENT, RECEIVED and rejects terminal statuses', function () {
    $this->lifecycle->assertCanCancel(Referral::STATUS_DRAFT);
    $this->lifecycle->assertCanCancel(Referral::STATUS_SENT);
    $this->lifecycle->assertCanCancel(Referral::STATUS_RECEIVED);

    $invalidStatuses = [
        Referral::STATUS_ACCEPTED,
        Referral::STATUS_COMPLETED,
        Referral::STATUS_REJECTED,
        Referral::STATUS_CANCELLED,
        Referral::STATUS_EXPIRED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanCancel($status))
            ->toThrow(IllegalReferralTransition::class);
    }
});

test('assertCanExpire allows SENT and RECEIVED and rejects other statuses', function () {
    $this->lifecycle->assertCanExpire(Referral::STATUS_SENT);
    $this->lifecycle->assertCanExpire(Referral::STATUS_RECEIVED);

    $invalidStatuses = [
        Referral::STATUS_DRAFT,
        Referral::STATUS_ACCEPTED,
        Referral::STATUS_COMPLETED,
        Referral::STATUS_REJECTED,
        Referral::STATUS_CANCELLED,
        Referral::STATUS_EXPIRED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanExpire($status))
            ->toThrow(IllegalReferralTransition::class);
    }
});

test('assertCanLinkEncounter allows only ACCEPTED', function () {
    $this->lifecycle->assertCanLinkEncounter(Referral::STATUS_ACCEPTED);

    $invalidStatuses = [
        Referral::STATUS_DRAFT,
        Referral::STATUS_SENT,
        Referral::STATUS_RECEIVED,
        Referral::STATUS_COMPLETED,
        Referral::STATUS_REJECTED,
        Referral::STATUS_CANCELLED,
        Referral::STATUS_EXPIRED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanLinkEncounter($status))
            ->toThrow(IllegalReferralTransition::class);
    }
});

test('assertCanAcknowledgeHandover requires impliesHandover to be true and status ACCEPTED', function () {
    // Valid: impliesHandover = true, status = ACCEPTED
    $this->lifecycle->assertCanAcknowledgeHandover(Referral::STATUS_ACCEPTED, true);

    // Rejected if impliesHandover = false
    expect(fn () => $this->lifecycle->assertCanAcknowledgeHandover(Referral::STATUS_ACCEPTED, false))
        ->toThrow(IllegalReferralTransition::class);

    // Rejected if status is not ACCEPTED even when impliesHandover = true
    $invalidStatuses = [
        Referral::STATUS_DRAFT,
        Referral::STATUS_SENT,
        Referral::STATUS_RECEIVED,
        Referral::STATUS_COMPLETED,
        Referral::STATUS_REJECTED,
        Referral::STATUS_CANCELLED,
        Referral::STATUS_EXPIRED,
    ];

    foreach ($invalidStatuses as $status) {
        expect(fn () => $this->lifecycle->assertCanAcknowledgeHandover($status, true))
            ->toThrow(IllegalReferralTransition::class);
    }
});

test('assertCanRecordContent allows EXPECTED and PARTIAL, rejects RECEIVED and NOT_PROVIDED', function () {
    $this->lifecycle->assertCanRecordContent(ReferralFeedback::STATUS_EXPECTED);
    $this->lifecycle->assertCanRecordContent(ReferralFeedback::STATUS_PARTIAL);

    expect(fn () => $this->lifecycle->assertCanRecordContent(ReferralFeedback::STATUS_RECEIVED))
        ->toThrow(IllegalReferralFeedbackTransition::class);
    expect(fn () => $this->lifecycle->assertCanRecordContent(ReferralFeedback::STATUS_NOT_PROVIDED))
        ->toThrow(IllegalReferralFeedbackTransition::class);
});

test('assertCanCompleteFeedback allows only PARTIAL and rejects EXPECTED, RECEIVED, NOT_PROVIDED', function () {
    $this->lifecycle->assertCanCompleteFeedback(ReferralFeedback::STATUS_PARTIAL);

    expect(fn () => $this->lifecycle->assertCanCompleteFeedback(ReferralFeedback::STATUS_EXPECTED))
        ->toThrow(IllegalReferralFeedbackTransition::class);
    expect(fn () => $this->lifecycle->assertCanCompleteFeedback(ReferralFeedback::STATUS_RECEIVED))
        ->toThrow(IllegalReferralFeedbackTransition::class);
    expect(fn () => $this->lifecycle->assertCanCompleteFeedback(ReferralFeedback::STATUS_NOT_PROVIDED))
        ->toThrow(IllegalReferralFeedbackTransition::class);
});

test('assertCanMarkFeedbackNotProvided allows EXPECTED and PARTIAL, rejects terminal statuses', function () {
    $this->lifecycle->assertCanMarkFeedbackNotProvided(ReferralFeedback::STATUS_EXPECTED);
    $this->lifecycle->assertCanMarkFeedbackNotProvided(ReferralFeedback::STATUS_PARTIAL);

    expect(fn () => $this->lifecycle->assertCanMarkFeedbackNotProvided(ReferralFeedback::STATUS_RECEIVED))
        ->toThrow(IllegalReferralFeedbackTransition::class);
    expect(fn () => $this->lifecycle->assertCanMarkFeedbackNotProvided(ReferralFeedback::STATUS_NOT_PROVIDED))
        ->toThrow(IllegalReferralFeedbackTransition::class);
});
