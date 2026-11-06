<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

final class LaboratoryCatalogEditorRoleDefinition
{
    public const KEY = 'laboratory_catalog_editor';

    public const PERMISSIONS = [
        'laboratory.catalog.manage',
    ];
}
