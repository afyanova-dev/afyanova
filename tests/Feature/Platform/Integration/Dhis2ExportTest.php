<?php

declare(strict_types=1);

use App\Platform\Integration\Infrastructure\Formatter\Dhis2ExportFormatter;

test('formats DHIS2 dataValueSets payload according to national reporting schema', function () {
    $formatter = new Dhis2ExportFormatter;

    $dataSetUid = 'pBvZ3mST0sl';
    $orgUnitUid = 'DiszpKrYNg8';
    $period = '202608';

    $dataValues = [
        ['dataElement' => 'f75vdZ5VqKa', 'value' => 124, 'categoryOptionCombo' => 'Prl0GzMfzDg'],
        ['dataElement' => 'qrur9Dvnyt5', 'value' => 88],
        ['dataElement' => 'l6byaqiAcD8', 'value' => 12, 'comment' => 'Stock-out resolved in week 3'],
    ];

    $payload = $formatter->formatDataValueSet(
        dataSetUid: $dataSetUid,
        orgUnitUid: $orgUnitUid,
        period: $period,
        dataValues: $dataValues,
        completeDate: '2026-09-01',
    );

    expect($payload)->toMatchArray([
        'dataSet' => 'pBvZ3mST0sl',
        'orgUnit' => 'DiszpKrYNg8',
        'period' => '202608',
        'completeDate' => '2026-09-01',
    ])
        ->and($payload['dataValues'])->toHaveCount(3)
        ->and($payload['dataValues'][0])->toBe([
            'dataElement' => 'f75vdZ5VqKa',
            'categoryOptionCombo' => 'Prl0GzMfzDg',
            'value' => '124',
        ])
        ->and($payload['dataValues'][1])->toBe([
            'dataElement' => 'qrur9Dvnyt5',
            'categoryOptionCombo' => 'default',
            'value' => '88',
        ])
        ->and($payload['dataValues'][2])->toBe([
            'dataElement' => 'l6byaqiAcD8',
            'categoryOptionCombo' => 'default',
            'value' => '12',
            'comment' => 'Stock-out resolved in week 3',
        ]);
});

test('throws InvalidArgumentException for malformed period or identifiers', function () {
    $formatter = new Dhis2ExportFormatter;

    expect(fn () => $formatter->formatDataValueSet('', 'org1', '202608', []))
        ->toThrow(InvalidArgumentException::class, 'DHIS2 export requires valid dataSet and orgUnit identifiers');

    expect(fn () => $formatter->formatDataValueSet('ds1', 'org1', 'invalid-period-format', []))
        ->toThrow(InvalidArgumentException::class, 'Invalid DHIS2 period format');
});
