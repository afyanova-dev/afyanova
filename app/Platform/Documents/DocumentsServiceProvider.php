<?php

declare(strict_types=1);

namespace App\Platform\Documents;

use App\Platform\Documents\Application\Command\AttachSignatureCommand;
use App\Platform\Documents\Application\Command\AttachSignatureHandler;
use App\Platform\Documents\Application\Command\DisposeDocumentVersionCommand;
use App\Platform\Documents\Application\Command\DisposeDocumentVersionHandler;
use App\Platform\Documents\Application\Command\GenerateDocumentCommand;
use App\Platform\Documents\Application\Command\GenerateDocumentHandler;
use App\Platform\Documents\Application\Command\GrantDocumentAccessCommand;
use App\Platform\Documents\Application\Command\GrantDocumentAccessHandler;
use App\Platform\Documents\Application\Command\ImposeRetentionRequirementCommand;
use App\Platform\Documents\Application\Command\ImposeRetentionRequirementHandler;
use App\Platform\Documents\Application\Command\RedactDocumentCommand;
use App\Platform\Documents\Application\Command\RedactDocumentHandler;
use App\Platform\Documents\Application\Command\ReleaseRetentionRequirementCommand;
use App\Platform\Documents\Application\Command\ReleaseRetentionRequirementHandler;
use App\Platform\Documents\Application\Command\RevokeDocumentAccessCommand;
use App\Platform\Documents\Application\Command\RevokeDocumentAccessHandler;
use App\Platform\Documents\Application\Command\UploadDocumentCommand;
use App\Platform\Documents\Application\Command\UploadDocumentHandler;
use App\Platform\Documents\Application\Query\GetDocumentHandler;
use App\Platform\Documents\Application\Query\GetDocumentQuery;
use App\Platform\Documents\Application\Query\GetDownloadStreamHandler;
use App\Platform\Documents\Application\Query\GetDownloadStreamQuery;
use App\Platform\Documents\Application\Query\GetDownloadUrlHandler;
use App\Platform\Documents\Application\Query\GetDownloadUrlQuery;
use App\Platform\Documents\Application\Query\GetRetentionStatusHandler;
use App\Platform\Documents\Application\Query\GetRetentionStatusQuery;
use App\Platform\Documents\Application\Query\VerifyDocumentReproducibilityHandler;
use App\Platform\Documents\Application\Query\VerifyDocumentReproducibilityQuery;
use App\Platform\Documents\Application\Query\VerifyDocumentSignatureHandler;
use App\Platform\Documents\Application\Query\VerifyDocumentSignatureQuery;
use App\Platform\Documents\Authorization\DocumentPolicy;
use App\Platform\Documents\Contracts\DocumentsApi;
use App\Platform\Documents\Domain\Model\Document;
use App\Platform\Documents\Domain\Port\AntivirusScannerPort;
use App\Platform\Documents\Domain\Port\DocumentStoragePort;
use App\Platform\Documents\Domain\Port\DocumentTemplateRendererPort;
use App\Platform\Documents\Domain\Port\PdfRendererPort;
use App\Platform\Documents\Domain\Repository\AccessGrantRepositoryInterface;
use App\Platform\Documents\Domain\Repository\ConsumedTokenRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentTemplateRepositoryInterface;
use App\Platform\Documents\Domain\Repository\DocumentVersionRepositoryInterface;
use App\Platform\Documents\Domain\Repository\RetentionRequirementRepositoryInterface;
use App\Platform\Documents\Domain\Repository\SignatureRepositoryInterface;
use App\Platform\Documents\Infrastructure\Adapter\BarcodeGenerator;
use App\Platform\Documents\Infrastructure\Adapter\BladeDocumentTemplateRenderer;
use App\Platform\Documents\Infrastructure\Adapter\ChromeHeadlessPdfRenderer;
use App\Platform\Documents\Infrastructure\Adapter\EscPosReceiptFormatter;
use App\Platform\Documents\Infrastructure\Adapter\FileSystemDocumentStorageAdapter;
use App\Platform\Documents\Infrastructure\Adapter\MockAntivirusScannerAdapter;
use App\Platform\Documents\Infrastructure\Repository\EloquentAccessGrantRepository;
use App\Platform\Documents\Infrastructure\Repository\EloquentConsumedTokenRepository;
use App\Platform\Documents\Infrastructure\Repository\EloquentDocumentRepository;
use App\Platform\Documents\Infrastructure\Repository\EloquentDocumentTemplateRepository;
use App\Platform\Documents\Infrastructure\Repository\EloquentDocumentVersionRepository;
use App\Platform\Documents\Infrastructure\Repository\EloquentRetentionRequirementRepository;
use App\Platform\Documents\Infrastructure\Repository\EloquentSignatureRepository;
use App\Platform\Documents\Infrastructure\Service\DocumentsApiService;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class DocumentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DocumentRepositoryInterface::class, EloquentDocumentRepository::class);
        $this->app->bind(DocumentVersionRepositoryInterface::class, EloquentDocumentVersionRepository::class);
        $this->app->bind(SignatureRepositoryInterface::class, EloquentSignatureRepository::class);
        $this->app->bind(AccessGrantRepositoryInterface::class, EloquentAccessGrantRepository::class);
        $this->app->bind(ConsumedTokenRepositoryInterface::class, EloquentConsumedTokenRepository::class);
        $this->app->bind(RetentionRequirementRepositoryInterface::class, EloquentRetentionRequirementRepository::class);
        $this->app->bind(DocumentTemplateRepositoryInterface::class, EloquentDocumentTemplateRepository::class);

        $this->app->bind(DocumentStoragePort::class, FileSystemDocumentStorageAdapter::class);
        $this->app->bind(AntivirusScannerPort::class, MockAntivirusScannerAdapter::class);
        $this->app->bind(DocumentTemplateRendererPort::class, BladeDocumentTemplateRenderer::class);
        $this->app->bind(PdfRendererPort::class, ChromeHeadlessPdfRenderer::class);
        $this->app->singleton(BarcodeGenerator::class);
        $this->app->bind(EscPosReceiptFormatter::class);

        $this->app->bind(DocumentsApi::class, DocumentsApiService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        CommandBus::map([
            UploadDocumentCommand::class => UploadDocumentHandler::class,
            GenerateDocumentCommand::class => GenerateDocumentHandler::class,
            AttachSignatureCommand::class => AttachSignatureHandler::class,
            GrantDocumentAccessCommand::class => GrantDocumentAccessHandler::class,
            RevokeDocumentAccessCommand::class => RevokeDocumentAccessHandler::class,
            ImposeRetentionRequirementCommand::class => ImposeRetentionRequirementHandler::class,
            ReleaseRetentionRequirementCommand::class => ReleaseRetentionRequirementHandler::class,
            RedactDocumentCommand::class => RedactDocumentHandler::class,
            DisposeDocumentVersionCommand::class => DisposeDocumentVersionHandler::class,
        ]);

        QueryBus::map([
            GetDocumentQuery::class => GetDocumentHandler::class,
            GetDownloadUrlQuery::class => GetDownloadUrlHandler::class,
            GetDownloadStreamQuery::class => GetDownloadStreamHandler::class,
            VerifyDocumentReproducibilityQuery::class => VerifyDocumentReproducibilityHandler::class,
            VerifyDocumentSignatureQuery::class => VerifyDocumentSignatureHandler::class,
            GetRetentionStatusQuery::class => GetRetentionStatusHandler::class,
        ]);

        Gate::policy(Document::class, DocumentPolicy::class);
    }
}
