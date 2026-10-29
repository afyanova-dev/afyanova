<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Adapter;

use App\Platform\Documents\Domain\Model\DocumentTemplate;
use App\Platform\Documents\Domain\Port\DocumentTemplateRendererPort;
use Illuminate\Support\Facades\Blade;

final class BladeDocumentTemplateRenderer implements DocumentTemplateRendererPort
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(DocumentTemplate $template, array $payload): string
    {
        return Blade::render($template->layout, $payload);
    }
}
