<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Documents;

use App\Platform\Documents\Infrastructure\Adapter\BarcodeGenerator;
use InvalidArgumentException;

test('BarcodeGenerator produces valid Code 128 SVG for specimen label', function (): void {
    $generator = new BarcodeGenerator;
    $svg = $generator->generateCode128Svg('SPEC-2026-0098', height: 40, barWidth: 2, showText: true);

    expect($svg)->toStartWith('<svg');
    expect($svg)->toEndWith('</svg>');
    expect($svg)->toContain('<rect');
    expect($svg)->toContain('SPEC-2026-0098');
});

test('BarcodeGenerator produces valid QR Code SVG for patient wristband token', function (): void {
    $generator = new BarcodeGenerator;
    $svg = $generator->generateQrCodeSvg('PAT-TOKEN-UUID7-778899', size: 150);

    expect($svg)->toStartWith('<svg');
    expect($svg)->toEndWith('</svg>');
    expect($svg)->toContain('viewBox="0 0 150 150"');
    expect($svg)->toContain('<rect');
});

test('BarcodeGenerator throws exception on empty or invalid inputs', function (): void {
    $generator = new BarcodeGenerator;

    expect(fn () => $generator->generateCode128Svg(''))
        ->toThrow(InvalidArgumentException::class);

    expect(fn () => $generator->generateQrCodeSvg(''))
        ->toThrow(InvalidArgumentException::class);
});
