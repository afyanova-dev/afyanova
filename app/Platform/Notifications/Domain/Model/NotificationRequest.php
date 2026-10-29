<?php

declare(strict_types=1);

namespace App\Platform\Notifications\Domain\Model;

use App\Platform\Notifications\Domain\Event\NotificationAccepted;
use App\Platform\Notifications\Domain\Event\NotificationDelivered;
use App\Platform\Notifications\Domain\Event\NotificationDispatched;
use App\Platform\Notifications\Domain\Event\NotificationFailed;
use App\Platform\Notifications\Domain\Event\NotificationSuppressed;
use App\Platform\Notifications\Domain\Exception\InvalidNotificationStateTransitionException;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * NotificationRequest aggregate root.
 *
 * @authority delivery (PLATFORM SERVICE)
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $template_key
 * @property int $template_version
 * @property RecipientType $recipient_type
 * @property string $recipient_id
 * @property string $on_behalf_of_domain
 * @property string $on_behalf_of_entity
 * @property string|null $consent_basis_ref
 * @property NotificationPriority $priority
 * @property string|null $dedupe_key
 * @property NotificationStatus $status
 * @property SuppressionReason|null $suppression_reason
 * @property array<string, mixed> $context_payload
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class NotificationRequest extends AggregateRoot
{
    protected $table = 'notif_requests';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'template_key',
        'template_version',
        'recipient_type',
        'recipient_id',
        'on_behalf_of_domain',
        'on_behalf_of_entity',
        'consent_basis_ref',
        'priority',
        'dedupe_key',
        'status',
        'suppression_reason',
        'context_payload',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'recipient_type' => RecipientType::class,
        'priority' => NotificationPriority::class,
        'status' => NotificationStatus::class,
        'suppression_reason' => SuppressionReason::class,
        'context_payload' => 'array',
        'template_version' => 'integer',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @param  array<string, mixed>  $contextPayload
     */
    public static function create(
        string $tenantId,
        string $templateKey,
        int $templateVersion,
        RecipientType $recipientType,
        string $recipientId,
        string $onBehalfOfDomain,
        string $onBehalfOfEntity,
        NotificationPriority $priority,
        array $contextPayload,
        ?string $consentBasisRef = null,
        ?string $dedupeKey = null,
    ): self {
        $now = CarbonImmutable::now();
        $id = (string) Str::uuid();

        // Enforce F3: Sanitize context payload to strip sensitive plaintext authentication secrets
        $sanitizedContext = self::sanitizeContext($contextPayload);

        $request = new self;
        $request->id = $id;
        $request->tenant_id = $tenantId;
        $request->template_key = $templateKey;
        $request->template_version = $templateVersion;
        $request->recipient_type = $recipientType;
        $request->recipient_id = $recipientId;
        $request->on_behalf_of_domain = $onBehalfOfDomain;
        $request->on_behalf_of_entity = $onBehalfOfEntity;
        $request->consent_basis_ref = $consentBasisRef;
        $request->priority = $priority;
        $request->dedupe_key = $dedupeKey;
        $request->status = NotificationStatus::ACCEPTED;
        $request->suppression_reason = null;
        $request->context_payload = $sanitizedContext;
        $request->version = 1;
        $request->created_at = $now;
        $request->updated_at = $now;

        $request->recordThat(new NotificationAccepted(
            tenantId: $tenantId,
            notificationId: $id,
            templateKey: $templateKey,
            recipientType: $recipientType->value,
            recipientId: $recipientId,
            priority: $priority->value,
            dedupeKey: $dedupeKey,
            createdAt: $now,
        ));

        return $request;
    }

    public function markRendered(): void
    {
        if ($this->status->isTerminal()) {
            throw InvalidNotificationStateTransitionException::transition($this->status->value, NotificationStatus::RENDERED->value);
        }

        $this->status = NotificationStatus::RENDERED;
        $this->version++;
    }

    public function markDispatched(NotificationChannel $channel, string $provider, ?string $maskedDestination = null): void
    {
        if ($this->status === NotificationStatus::SUPPRESSED || $this->status === NotificationStatus::FAILED) {
            throw InvalidNotificationStateTransitionException::transition($this->status->value, NotificationStatus::DISPATCHED->value);
        }

        $now = CarbonImmutable::now();
        $this->status = NotificationStatus::DISPATCHED;
        $this->version++;

        $this->recordThat(new NotificationDispatched(
            tenantId: $this->tenant_id,
            notificationId: $this->id,
            templateKey: $this->template_key,
            channel: $channel->value,
            provider: $provider,
            maskedDestination: $maskedDestination,
            dispatchedAt: $now,
        ));
    }

    public function markDelivered(NotificationChannel $channel, string $provider): void
    {
        $now = CarbonImmutable::now();
        $this->status = NotificationStatus::DELIVERED;
        $this->version++;

        $this->recordThat(new NotificationDelivered(
            tenantId: $this->tenant_id,
            notificationId: $this->id,
            channel: $channel->value,
            provider: $provider,
            deliveredAt: $now,
        ));
    }

    public function markFailed(NotificationChannel $channel, string $provider, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $now = CarbonImmutable::now();
        $this->status = NotificationStatus::FAILED;
        $this->version++;

        $this->recordThat(new NotificationFailed(
            tenantId: $this->tenant_id,
            notificationId: $this->id,
            channel: $channel->value,
            provider: $provider,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            failedAt: $now,
        ));
    }

    public function markSuppressed(SuppressionReason $reason, ?string $details = null): void
    {
        $now = CarbonImmutable::now();
        $this->status = NotificationStatus::SUPPRESSED;
        $this->suppression_reason = $reason;
        $this->version++;

        $this->recordThat(new NotificationSuppressed(
            tenantId: $this->tenant_id,
            notificationId: $this->id,
            suppressionReason: $reason->value,
            details: $details,
            suppressedAt: $now,
        ));
    }

    /**
     * @return HasMany<DeliveryAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class, 'notification_request_id');
    }

    /**
     * F3: Sanitize context payload so authentication tokens/passwords are NEVER persisted in DB
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $sensitiveKeys = ['otp', 'otp_code', 'token', 'password', 'secret', 'auth_code', 'pin'];
        $clean = [];

        foreach ($context as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $clean[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $clean[$key] = self::sanitizeContext($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
