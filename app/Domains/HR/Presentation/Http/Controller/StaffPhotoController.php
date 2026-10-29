<?php

declare(strict_types=1);

namespace App\Domains\HR\Presentation\Http\Controller;

use App\Domains\HR\Contracts\HRApi;
use App\Models\User;
use App\Platform\Documents\Application\Query\GetDownloadStreamQuery;
use App\Platform\Documents\Domain\Exception\DocumentNotAvailableException;
use App\Platform\Documents\Domain\Exception\DocumentNotFoundException;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StaffPhotoController
{
    public function __invoke(
        string $staff,
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        QueryBus $queryBus,
        HRApi $hrApi,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityId = $context->facilityId();
        abort_unless(is_string($facilityId) && $facilityId !== '', 403, 'No active facility context.');
        abort_unless($scopeChecker->withinFacilityScope($user, $facilityId), 403, 'Unauthorized.');
        $permissions = $scopeChecker->effectivePermissions($user, $facilityId);
        abort_unless(
            in_array('hr.staff.read', $permissions, true) || in_array('hr.staff.manage', $permissions, true)
            || $this->canReviewAssignments($permissions),
            403,
            'Unauthorized.',
        );

        $photoDocumentId = $hrApi->getStaffPhotoDocumentId(
            (string) $context->tenantId(),
            $facilityId,
            $staff,
            CarbonImmutable::today(),
        );

        abort_unless(is_string($photoDocumentId), 404, 'Staff photo not found.');

        try {
            $stream = $queryBus->dispatch(new GetDownloadStreamQuery(
                tenantId: (string) $context->tenantId(),
                documentId: $photoDocumentId,
                actorId: (string) $user->getKey(),
            ));
        } catch (DocumentNotAvailableException $exception) {
            abort(403, $exception->getMessage());
        } catch (DocumentNotFoundException) {
            abort(404, 'Staff photo not found.');
        }

        return response($stream->contents, 200, [
            'Content-Type' => $stream->mimeType,
            'Content-Length' => (string) $stream->byteSize,
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Assignment reviewers (e.g. Facility Admins) may open staff records
     * read-only, so they may load record photos too — mirroring
     * FacilityStaffController::canReviewAssignments.
     *
     * @param  list<string>  $permissions
     */
    private function canReviewAssignments(array $permissions): bool
    {
        return array_diff(['admin.workspace.access', 'identity.assignments.manage'], $permissions) === []
            || array_diff(['hr.staff.manage', 'hr.assignment.manage'], $permissions) === [];
    }
}
