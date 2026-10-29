<?php

declare(strict_types=1);

namespace App\Platform\Integration;

use App\Platform\Integration\Application\Command\ConfigureWebhookEndpointCommand;
use App\Platform\Integration\Application\Command\ConfigureWebhookEndpointHandler;
use App\Platform\Integration\Application\Command\IngestFileImportCommand;
use App\Platform\Integration\Application\Command\IngestFileImportHandler;
use App\Platform\Integration\Application\Command\ReceiveInboundMessageCommand;
use App\Platform\Integration\Application\Command\ReceiveInboundMessageHandler;
use App\Platform\Integration\Application\Command\RecordImportRecordOutcomeCommand;
use App\Platform\Integration\Application\Command\RecordImportRecordOutcomeHandler;
use App\Platform\Integration\Application\Command\RegisterConnectorCommand;
use App\Platform\Integration\Application\Command\RegisterConnectorHandler;
use App\Platform\Integration\Application\Command\ReplayInboundMessageCommand;
use App\Platform\Integration\Application\Command\ReplayInboundMessageHandler;
use App\Platform\Integration\Application\Command\ResolveDeadLetterCommand;
use App\Platform\Integration\Application\Command\ResolveDeadLetterHandler;
use App\Platform\Integration\Application\Command\RetryOutboundMessageCommand;
use App\Platform\Integration\Application\Command\RetryOutboundMessageHandler;
use App\Platform\Integration\Application\Command\RotateCredentialCommand;
use App\Platform\Integration\Application\Command\RotateCredentialHandler;
use App\Platform\Integration\Application\Command\SendOutboundMessageCommand;
use App\Platform\Integration\Application\Command\SendOutboundMessageHandler;
use App\Platform\Integration\Application\Command\UpdateConnectorStatusCommand;
use App\Platform\Integration\Application\Command\UpdateConnectorStatusHandler;
use App\Platform\Integration\Application\Command\UpsertIdMappingCommand;
use App\Platform\Integration\Application\Command\UpsertIdMappingHandler;
use App\Platform\Integration\Application\Pipeline\InboundAirlockPipeline;
use App\Platform\Integration\Application\Pipeline\OutboundDeliveryPipeline;
use App\Platform\Integration\Application\Query\GetConnectorHandler;
use App\Platform\Integration\Application\Query\GetConnectorQuery;
use App\Platform\Integration\Application\Query\GetFileImportReconciliationHandler;
use App\Platform\Integration\Application\Query\GetFileImportReconciliationQuery;
use App\Platform\Integration\Application\Query\GetInboundMessageHandler;
use App\Platform\Integration\Application\Query\GetInboundMessageQuery;
use App\Platform\Integration\Application\Query\GetOutboundMessageHandler;
use App\Platform\Integration\Application\Query\GetOutboundMessageQuery;
use App\Platform\Integration\Application\Query\ListConnectorsHandler;
use App\Platform\Integration\Application\Query\ListConnectorsQuery;
use App\Platform\Integration\Application\Query\ResolveIdMappingHandler;
use App\Platform\Integration\Application\Query\ResolveIdMappingQuery;
use App\Platform\Integration\Application\Service\FileImportReconciliationService;
use App\Platform\Integration\Application\Service\IdMappingService;
use App\Platform\Integration\Authorization\ConnectorPolicy;
use App\Platform\Integration\Authorization\FileImportPolicy;
use App\Platform\Integration\Authorization\MappingPolicy;
use App\Platform\Integration\Authorization\MessagePolicy;
use App\Platform\Integration\Contracts\Fixture\SyntheticDomainDispatcher;
use App\Platform\Integration\Contracts\Fixture\SyntheticInboundTranslator;
use App\Platform\Integration\Contracts\Fixture\SyntheticTransportClient;
use App\Platform\Integration\Contracts\Port\DomainDispatcherPort;
use App\Platform\Integration\Contracts\Port\InboundTranslatorPort;
use App\Platform\Integration\Contracts\Port\IntegrationTransportClient;
use App\Platform\Integration\Contracts\Port\PayloadSanitizerPort;
use App\Platform\Integration\Contracts\Port\SecretResolverPort;
use App\Platform\Integration\Contracts\Port\WebhookAuthenticatorPort;
use App\Platform\Integration\Domain\Model\FileImport;
use App\Platform\Integration\Domain\Model\IdMapping;
use App\Platform\Integration\Domain\Model\InboundMessage;
use App\Platform\Integration\Domain\Model\IntegrationConnector;
use App\Platform\Integration\Domain\Model\OutboundMessage;
use App\Platform\Integration\Domain\Repository\ConnectorRepository;
use App\Platform\Integration\Domain\Repository\CredentialRepository;
use App\Platform\Integration\Domain\Repository\FileImportRepository;
use App\Platform\Integration\Domain\Repository\IdMappingRepository;
use App\Platform\Integration\Domain\Repository\InboundMessageRepository;
use App\Platform\Integration\Domain\Repository\OutboundMessageRepository;
use App\Platform\Integration\Domain\Repository\WebhookEndpointRepository;
use App\Platform\Integration\Infrastructure\Authenticator\BearerTokenAuthenticator;
use App\Platform\Integration\Infrastructure\Authenticator\CompositeWebhookAuthenticator;
use App\Platform\Integration\Infrastructure\Authenticator\HmacSha256Authenticator;
use App\Platform\Integration\Infrastructure\Formatter\Dhis2ExportFormatter;
use App\Platform\Integration\Infrastructure\Repository\EloquentConnectorRepository;
use App\Platform\Integration\Infrastructure\Repository\EloquentCredentialRepository;
use App\Platform\Integration\Infrastructure\Repository\EloquentFileImportRepository;
use App\Platform\Integration\Infrastructure\Repository\EloquentIdMappingRepository;
use App\Platform\Integration\Infrastructure\Repository\EloquentInboundMessageRepository;
use App\Platform\Integration\Infrastructure\Repository\EloquentOutboundMessageRepository;
use App\Platform\Integration\Infrastructure\Repository\EloquentWebhookEndpointRepository;
use App\Platform\Integration\Infrastructure\Sanitizer\PayloadSanitizer;
use App\Platform\Integration\Infrastructure\Secret\ConfigSecretResolver;
use App\Platform\Integration\Infrastructure\Translator\CompositeInboundTranslator;
use App\Platform\Integration\Infrastructure\Translator\DicomInboundTranslator;
use App\Platform\Integration\Infrastructure\Translator\Hl7InboundTranslator;
use App\Platform\Integration\Infrastructure\Translator\MpesaInboundTranslator;
use App\Platform\Integration\Infrastructure\Transport\HttpTransportClient;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Repositories
        $this->app->bind(ConnectorRepository::class, EloquentConnectorRepository::class);
        $this->app->bind(CredentialRepository::class, EloquentCredentialRepository::class);
        $this->app->bind(WebhookEndpointRepository::class, EloquentWebhookEndpointRepository::class);
        $this->app->bind(InboundMessageRepository::class, EloquentInboundMessageRepository::class);
        $this->app->bind(OutboundMessageRepository::class, EloquentOutboundMessageRepository::class);
        $this->app->bind(IdMappingRepository::class, EloquentIdMappingRepository::class);
        $this->app->bind(FileImportRepository::class, EloquentFileImportRepository::class);

        // Secrets, Sanitizers, Authenticators
        $this->app->singleton(SecretResolverPort::class, ConfigSecretResolver::class);
        $this->app->singleton(ConfigSecretResolver::class);
        $this->app->bind(PayloadSanitizerPort::class, PayloadSanitizer::class);
        $this->app->bind(HmacSha256Authenticator::class);
        $this->app->bind(BearerTokenAuthenticator::class);
        $this->app->bind(WebhookAuthenticatorPort::class, CompositeWebhookAuthenticator::class);

        // Translators (P13 production adapters with Composite router)
        $this->app->singleton(MpesaInboundTranslator::class);
        $this->app->singleton(Hl7InboundTranslator::class);
        $this->app->singleton(DicomInboundTranslator::class);
        $this->app->singleton(SyntheticInboundTranslator::class);
        $this->app->singleton(CompositeInboundTranslator::class);
        $this->app->singleton(InboundTranslatorPort::class, CompositeInboundTranslator::class);

        // Domain Dispatcher (Fixture contract test double for P13)
        $this->app->singleton(DomainDispatcherPort::class, SyntheticDomainDispatcher::class);
        $this->app->singleton(SyntheticDomainDispatcher::class);

        // Transport Clients (HTTP production client + Synthetic double for testing)
        $this->app->singleton(HttpTransportClient::class);
        $this->app->singleton(SyntheticTransportClient::class);
        $this->app->singleton(IntegrationTransportClient::class, function ($app) {
            if ($app->environment('testing') && ! (bool) config('integration.use_real_transport', false)) {
                return $app->make(SyntheticTransportClient::class);
            }

            return $app->make(HttpTransportClient::class);
        });

        // Formatters
        $this->app->singleton(Dhis2ExportFormatter::class);

        // Pipelines & Services
        $this->app->bind(InboundAirlockPipeline::class);
        $this->app->bind(OutboundDeliveryPipeline::class);
        $this->app->bind(IdMappingService::class);
        $this->app->bind(FileImportReconciliationService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            RegisterConnectorCommand::class => RegisterConnectorHandler::class,
            UpdateConnectorStatusCommand::class => UpdateConnectorStatusHandler::class,
            RotateCredentialCommand::class => RotateCredentialHandler::class,
            ConfigureWebhookEndpointCommand::class => ConfigureWebhookEndpointHandler::class,
            ReceiveInboundMessageCommand::class => ReceiveInboundMessageHandler::class,
            ReplayInboundMessageCommand::class => ReplayInboundMessageHandler::class,
            SendOutboundMessageCommand::class => SendOutboundMessageHandler::class,
            RetryOutboundMessageCommand::class => RetryOutboundMessageHandler::class,
            ResolveDeadLetterCommand::class => ResolveDeadLetterHandler::class,
            UpsertIdMappingCommand::class => UpsertIdMappingHandler::class,
            IngestFileImportCommand::class => IngestFileImportHandler::class,
            RecordImportRecordOutcomeCommand::class => RecordImportRecordOutcomeHandler::class,
        ]);

        QueryBus::map([
            GetConnectorQuery::class => GetConnectorHandler::class,
            ListConnectorsQuery::class => ListConnectorsHandler::class,
            GetInboundMessageQuery::class => GetInboundMessageHandler::class,
            GetOutboundMessageQuery::class => GetOutboundMessageHandler::class,
            ResolveIdMappingQuery::class => ResolveIdMappingHandler::class,
            GetFileImportReconciliationQuery::class => GetFileImportReconciliationHandler::class,
        ]);

        Gate::policy(IntegrationConnector::class, ConnectorPolicy::class);
        Gate::policy(InboundMessage::class, MessagePolicy::class);
        Gate::policy(OutboundMessage::class, MessagePolicy::class);
        Gate::policy(IdMapping::class, MappingPolicy::class);
        Gate::policy(FileImport::class, FileImportPolicy::class);
    }
}
