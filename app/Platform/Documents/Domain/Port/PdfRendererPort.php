<?php

declare(strict_types=1);

namespace App\Platform\Documents\Domain\Port;

interface PdfRendererPort
{
    /**
     * Convert HTML content into raw binary PDF bytes.
     */
    public function renderHtmlToPdf(string $html): string;
}
