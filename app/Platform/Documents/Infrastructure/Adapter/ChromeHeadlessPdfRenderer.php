<?php

declare(strict_types=1);

namespace App\Platform\Documents\Infrastructure\Adapter;

use App\Platform\Documents\Domain\Port\PdfRendererPort;
use RuntimeException;
use Symfony\Component\Process\Process;

final class ChromeHeadlessPdfRenderer implements PdfRendererPort
{
    private readonly string $chromePath;

    private readonly int $timeoutSeconds;

    public function __construct(
        ?string $chromePath = null,
        ?int $timeoutSeconds = null,
    ) {
        $this->chromePath = $chromePath ?? (string) config('documents.chrome_path', '/usr/bin/google-chrome');
        $this->timeoutSeconds = $timeoutSeconds ?? (int) config('documents.timeout_seconds', 30);
    }

    public function renderHtmlToPdf(string $html): string
    {
        $random = bin2hex(random_bytes(16));
        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        $tempHtml = "{$tempDir}/afya_doc_{$random}.html";
        $tempPdf = "{$tempDir}/afya_pdf_{$random}.pdf";

        try {
            file_put_contents($tempHtml, $html);

            $binary = $this->resolveChromeBinary();

            $process = new Process([
                $binary,
                '--headless',
                '--disable-gpu',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--hide-scrollbars',
                "--print-to-pdf={$tempPdf}",
                $tempHtml,
            ]);

            $process->setTimeout($this->timeoutSeconds);
            $process->run();

            if (! $process->isSuccessful() || ! file_exists($tempPdf) || filesize($tempPdf) === 0) {
                throw new RuntimeException(
                    'Chrome headless PDF generation failed: '.$process->getErrorOutput()
                );
            }

            $pdfBytes = (string) file_get_contents($tempPdf);

            return $pdfBytes;
        } finally {
            if (file_exists($tempHtml)) {
                @unlink($tempHtml);
            }
            if (file_exists($tempPdf)) {
                @unlink($tempPdf);
            }
        }
    }

    private function resolveChromeBinary(): string
    {
        if (file_exists($this->chromePath) && is_executable($this->chromePath)) {
            return $this->chromePath;
        }

        foreach (['/usr/bin/chromium-browser', '/usr/bin/chromium', 'google-chrome', 'chromium'] as $fallback) {
            if (file_exists($fallback) && is_executable($fallback)) {
                return $fallback;
            }
        }

        return $this->chromePath;
    }
}
