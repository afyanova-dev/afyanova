<?php

declare(strict_types=1);

namespace App\Domains\Patient\Presentation\Http\Controller;

use App\Domains\Patient\Domain\PatientRepository;
use App\Models\User;
use App\Platform\Documents\Application\Query\GetDownloadStreamQuery;
use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PatientPhotoController
{
    public function __invoke(
        string $patientId,
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
        PatientRepository $patients,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        abort_unless($user->can('patient.patients.read'), 403, 'Unauthorized.');

        try {
            $patient = $patients->getById($patientId);
        } catch (NotFound) {
            abort(404, 'Patient not found.');
        }
        abort_if($patient->photo_document_id === null, 404, 'No photo on record.');

        try {
            $stream = $queryBus->dispatch(new GetDownloadStreamQuery(
                tenantId: (string) $context->tenantId(),
                documentId: (string) $patient->photo_document_id,
                actorId: (string) $user->getKey(),
            ));
        } catch (DocumentNotAvailableException $e) {
            abort(403, $e->getMessage());
        } catch (DocumentNotFoundException) {
            abort(404, 'Photo not found.');
        }

        return response($stream->contents, 200, [
            'Content-Type' => $stream->mimeType,
            'Content-Length' => (string) $stream->byteSize,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
