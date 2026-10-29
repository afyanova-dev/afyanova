<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Infrastructure\Job;

use App\Domains\HR\Contracts\HRApi;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Patient\Contracts\PatientApi;
use App\Platform\Notifications\Contracts\DTO\InAppNotificationDto;
use App\Platform\Notifications\Domain\Model\DeliveryAttempt;
use App\Platform\Notifications\Domain\Model\DeliveryAttemptStatus;
use App\Platform\Notifications\Domain\Model\InAppNotification;
use App\Platform\Notifications\Domain\Model\NotificationChannel;
use App\Platform\Notifications\Domain\Model\RecipientType;
use App\Platform\Notifications\Domain\Model\SuppressionReason;
use App\Platform\Notifications\Domain\Port\EmailGatewayPort;
use App\Platform\Notifications\Domain\Port\InAppBroadcasterPort;
use App\Platform\Notifications\Domain\Port\SmsGatewayPort;
use App\Platform\Notifications\Domain\Repository\ChannelPreferenceRepositoryInterface;
use App\Platform\Notifications\Domain\Repository\InAppNotificationRepositoryInterface;
use App\Platform\Notifications\Domain\Repository\NotificationRequestRepositoryInterface;
use App\Platform\Notifications\Domain\Repository\NotificationTemplateRepositoryInterface;
use App\Shared\Jobs\TenantAwareJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class DispatchNotificationJob extends TenantAwareJob
{
    /**
     * @param  list<string>|null  $requestedChannels
     * @param  array<string, mixed>  $rawContext
     */
    public function __construct(
        public readonly string $requestId,
        public readonly ?array $requestedChannels = null,
        public readonly array $rawContext = [],
        ?string $tenantId = null,
    ) {
        parent::__construct(tenantId: $tenantId);
    }

    protected function execute(): void
    {
        /** @var NotificationRequestRepositoryInterface $requestRepository */
        $requestRepository = app(NotificationRequestRepositoryInterface::class);
        /** @var NotificationTemplateRepositoryInterface $templateRepository */
        $templateRepository = app(NotificationTemplateRepositoryInterface::class);
        /** @var ChannelPreferenceRepositoryInterface $preferenceRepository */
        $preferenceRepository = app(ChannelPreferenceRepositoryInterface::class);
        /** @var InAppNotificationRepositoryInterface $inAppRepository */
        $inAppRepository = app(InAppNotificationRepositoryInterface::class);
        /** @var SmsGatewayPort $smsGateway */
        $smsGateway = app(SmsGatewayPort::class);
        /** @var EmailGatewayPort $emailGateway */
        $emailGateway = app(EmailGatewayPort::class);
        /** @var InAppBroadcasterPort $broadcaster */
        $broadcaster = app(InAppBroadcasterPort::class);
        /** @var PatientApi $patientApi */
        $patientApi = app(PatientApi::class);
        /** @var HRApi $hrApi */
        $hrApi = app(HRApi::class);
        /** @var IdentityApi $identityApi */
        $identityApi = app(IdentityApi::class);

        $request = $requestRepository->findById($this->requestId);
        if ($request === null || $request->status->isTerminal()) {
            return;
        }

        $template = $templateRepository->findByKey($request->template_key, $request->template_version);
        if ($template === null || ! $template->is_active) {
            $request->markFailed(NotificationChannel::SMS, 'SYSTEM', 'TEMPLATE_NOT_FOUND', "Template [{$request->template_key}] version [{$request->template_version}] not found or inactive.");
            $requestRepository->save($request);

            return;
        }

        // F2: Verify statutory consent if required by template
        if ($template->consent_required) {
            if ($request->consent_basis_ref === null || trim($request->consent_basis_ref) === '') {
                $request->markSuppressed(SuppressionReason::NO_CONSENT, 'Template requires valid consent basis reference');
                $requestRepository->save($request);

                return;
            }

            if ($request->recipient_type === RecipientType::PATIENT) {
                $patientContact = $patientApi->getNotificationContact($request->recipient_id);
                if (! $patientContact?->hasCommunicationConsent) {
                    $request->markSuppressed(SuppressionReason::NO_CONSENT, 'Patient has revoked communication consent');
                    $requestRepository->save($request);

                    return;
                }
            }
        }

        // F1 & F4: Resolve live recipient contact points without persisting raw PII
        $phone = null;
        $email = null;
        $language = 'sw';
        $userId = null;

        if ($request->recipient_type === RecipientType::PATIENT) {
            $contact = $patientApi->getNotificationContact($request->recipient_id);
            if ($contact === null) {
                $request->markSuppressed(SuppressionReason::INVALID_ADDRESS, 'Patient contact could not be resolved');
                $requestRepository->save($request);

                return;
            }
            $phone = $contact->phone;
            $email = $contact->email;
            $language = $contact->preferredLanguage;
        } elseif ($request->recipient_type === RecipientType::STAFF) {
            $contact = $hrApi->getStaffNotificationContact($request->recipient_id);
            if ($contact === null) {
                $request->markSuppressed(SuppressionReason::INVALID_ADDRESS, 'Staff contact could not be resolved');
                $requestRepository->save($request);

                return;
            }
            $phone = $contact->phone;
            $email = $contact->email;
            $userId = $contact->userId;
        } elseif ($request->recipient_type === RecipientType::USER) {
            if (! $identityApi->userExists($request->recipient_id)) {
                $request->markSuppressed(SuppressionReason::NO_USER_ACCOUNT, 'User account does not exist');
                $requestRepository->save($request);

                return;
            }
            $userId = $request->recipient_id;
        }

        // Operational Channel & Timing Preferences
        $preference = $preferenceRepository->findBySubject($request->recipient_type, $request->recipient_id);
        if ($preference !== null) {
            if ($preference->isCategoryMuted($template->category)) {
                $request->markSuppressed(SuppressionReason::OPT_OUT, "Category [{$template->category->value}] muted by recipient preferences");
                $requestRepository->save($request);

                return;
            }

            // Quiet hours evaluation (EMERGENCY bypasses quiet hours)
            if (! $request->priority->bypassesQuietHours() && $preference->quiet_hours_start !== null && $preference->quiet_hours_end !== null) {
                $nowTime = CarbonImmutable::now()->format('H:i');
                if ($this->isInQuietHours($nowTime, $preference->quiet_hours_start, $preference->quiet_hours_end)) {
                    $request->markSuppressed(SuppressionReason::QUIET_HOURS, "Suppressed due to quiet hours ({$preference->quiet_hours_start} - {$preference->quiet_hours_end})");
                    $requestRepository->save($request);

                    return;
                }
            }

            if (! empty($preference->preferred_language)) {
                $language = $preference->preferred_language;
            }
        }

        // Channel Selection
        $allowedChannels = $template->channels;
        $targetChannels = $this->requestedChannels ?? $allowedChannels;
        $channelsToDispatch = array_values(array_intersect($targetChannels, $allowedChannels));

        if (empty($channelsToDispatch)) {
            $request->markSuppressed(SuppressionReason::INVALID_ADDRESS, 'No compatible channels available between requested and template allowed channels');
            $requestRepository->save($request);

            return;
        }

        $request->markRendered();

        $anyDelivered = false;
        $anyAttempted = false;
        $allSuppressed = true;
        $firstDeliveredChannel = NotificationChannel::SMS;
        $firstDeliveredProvider = 'LOG';
        $lastChannel = NotificationChannel::SMS;
        $lastProvider = 'LOG';

        $mergedContext = array_merge($request->context_payload, $this->rawContext);

        foreach ($channelsToDispatch as $channelStr) {
            $channel = NotificationChannel::tryFrom($channelStr);
            if ($channel === null) {
                continue;
            }

            $lastChannel = $channel;
            $now = CarbonImmutable::now();

            $contentConfig = $template->content[strtolower($channel->value)] ?? [];
            $bodyTemplate = $contentConfig[$language] ?? $contentConfig['sw'] ?? $contentConfig['en'] ?? '';
            $subjectTemplate = $contentConfig['subject'] ?? 'AfyaNova Notification';

            $renderedBody = $this->renderTemplate($bodyTemplate, $mergedContext);
            $renderedSubject = $this->renderTemplate($subjectTemplate, $mergedContext);

            if ($channel === NotificationChannel::SMS) {
                if (empty($phone)) {
                    $this->recordAttempt($request->id, $channel, 'SMS_GATEWAY', null, null, DeliveryAttemptStatus::SUPPRESSED, 'INVALID_ADDRESS', 'Recipient has no phone number');

                    continue;
                }

                $anyAttempted = true;
                $maskedPhone = $this->maskPhone($phone);
                $result = $smsGateway->sendSms($phone, $renderedBody);
                $provider = 'SMS_GATEWAY';
                $lastProvider = $provider;

                if ($result->success) {
                    $allSuppressed = false;
                    $anyDelivered = true;
                    $firstDeliveredChannel = $channel;
                    $firstDeliveredProvider = $provider;
                    $this->recordAttempt($request->id, $channel, $provider, $result->providerMessageId, $maskedPhone, DeliveryAttemptStatus::DELIVERED, null, null, $now);
                } else {
                    $allSuppressed = false;
                    $this->recordAttempt($request->id, $channel, $provider, null, $maskedPhone, DeliveryAttemptStatus::FAILED, $result->errorCode, $result->errorMessage);
                }
            } elseif ($channel === NotificationChannel::EMAIL) {
                if (empty($email)) {
                    $this->recordAttempt($request->id, $channel, 'EMAIL_GATEWAY', null, null, DeliveryAttemptStatus::SUPPRESSED, 'INVALID_ADDRESS', 'Recipient has no email address');

                    continue;
                }

                $anyAttempted = true;
                $maskedEmail = $this->maskEmail($email);
                $result = $emailGateway->sendEmail($email, $renderedSubject, $renderedBody);
                $provider = 'EMAIL_GATEWAY';
                $lastProvider = $provider;

                if ($result->success) {
                    $allSuppressed = false;
                    $anyDelivered = true;
                    $firstDeliveredChannel = $channel;
                    $firstDeliveredProvider = $provider;
                    $this->recordAttempt($request->id, $channel, $provider, $result->providerMessageId, $maskedEmail, DeliveryAttemptStatus::DELIVERED, null, null, $now);
                } else {
                    $allSuppressed = false;
                    $this->recordAttempt($request->id, $channel, $provider, null, $maskedEmail, DeliveryAttemptStatus::FAILED, $result->errorCode, $result->errorMessage);
                }
            } elseif ($channel === NotificationChannel::IN_APP) {
                if (empty($userId)) {
                    // F7: In-app delivery requires a resolved user account; otherwise SUPPRESSED
                    $this->recordAttempt($request->id, $channel, 'REVERB', null, null, DeliveryAttemptStatus::SUPPRESSED, 'NO_USER_ACCOUNT', 'Recipient has no linked user account');

                    continue;
                }

                $anyAttempted = true;
                $provider = 'REVERB';
                $lastProvider = $provider;

                $inApp = new InAppNotification([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $request->tenant_id,
                    'user_id' => $userId,
                    'notification_request_id' => $request->id,
                    'title' => $renderedSubject,
                    'body' => $renderedBody,
                    'action_url' => $mergedContext['action_url'] ?? null,
                    'read_at' => null,
                ]);
                $inAppRepository->save($inApp);

                $inAppDto = new InAppNotificationDto(
                    id: (string) $inApp->id,
                    userId: $userId,
                    notificationRequestId: $request->id,
                    title: $inApp->title,
                    body: $inApp->body,
                    actionUrl: $inApp->action_url,
                    readAt: null,
                    createdAt: CarbonImmutable::now(),
                );

                $broadcaster->broadcastToUser($request->tenant_id, $userId, $inAppDto);

                $allSuppressed = false;
                $anyDelivered = true;
                $firstDeliveredChannel = $channel;
                $firstDeliveredProvider = $provider;
                $this->recordAttempt($request->id, $channel, $provider, 'INAPP-'.$inApp->id, $userId, DeliveryAttemptStatus::DELIVERED, null, null, $now);
            }
        }

        if ($anyDelivered) {
            $request->markDelivered($firstDeliveredChannel, $firstDeliveredProvider);
        } elseif ($allSuppressed || ! $anyAttempted) {
            $request->markSuppressed(SuppressionReason::INVALID_ADDRESS, 'All configured delivery channels were suppressed or unavailable');
        } else {
            $request->markFailed($lastChannel, $lastProvider, 'FAILED', 'All delivery attempts failed');
        }

        $requestRepository->save($request);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderTemplate(string $template, array $context): string
    {
        $rendered = $template;
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $rendered = str_replace('{'.$key.'}', (string) $value, $rendered);
            }
        }

        return $rendered;
    }

    private function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 5) {
            return '***';
        }

        return substr($phone, 0, 4).'*****'.substr($phone, -2);
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }

        return substr($parts[0], 0, 1).'***@'.$parts[1];
    }

    private function isInQuietHours(string $nowTime, string $start, string $end): bool
    {
        if ($start <= $end) {
            return $nowTime >= $start && $nowTime <= $end;
        }

        // Crosses midnight: e.g. 22:00 to 06:30
        return $nowTime >= $start || $nowTime <= $end;
    }

    private function recordAttempt(
        string $requestId,
        NotificationChannel $channel,
        string $provider,
        ?string $providerMessageId,
        ?string $maskedDestination,
        DeliveryAttemptStatus $status,
        ?string $errorCode,
        ?string $errorMessage,
        ?CarbonImmutable $deliveredAt = null,
    ): void {
        DeliveryAttempt::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'notification_request_id' => $requestId,
            'channel' => $channel->value,
            'provider' => $provider,
            'provider_message_id' => $providerMessageId,
            'masked_destination' => $maskedDestination,
            'status' => $status->value,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'attempted_at' => CarbonImmutable::now(),
            'delivered_at' => $deliveredAt,
        ]);
    }
}
