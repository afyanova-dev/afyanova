<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Port;

use App\Platform\Documents\Domain\Model\DocumentTemplate;

interface DocumentTemplateRendererPort
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(DocumentTemplate $template, array $payload): string;
}
