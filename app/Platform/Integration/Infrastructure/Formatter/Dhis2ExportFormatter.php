<?php

declare(strict_types=1);

namespace App\Platform\Integration\Infrastructure\Formatter;

use InvalidArgumentException;

final class Dhis2ExportFormatter
{
    /**
     * @param  list<array{dataElement: string, value: int|float|string, categoryOptionCombo?: string, comment?: string}>  $dataValues
     * @return array{dataSet: string, completeDate: string, period: string, orgUnit: string, dataValues: list<array{dataElement: string, categoryOptionCombo: string, value: string, comment?: string}>}
     */
    public function formatDataValueSet(
        string $dataSetUid,
        string $orgUnitUid,
        string $period,
        array $dataValues,
        ?string $completeDate = null,
        string $defaultCategoryOptionCombo = 'default',
    ): array {
        if ($dataSetUid === '' || $orgUnitUid === '') {
            throw new InvalidArgumentException('DHIS2 export requires valid dataSet and orgUnit identifiers.');
        }

        if (! preg_match('/^\d{4}(0[1-9]|1[0-2])|\d{4}W\d{1,2}|\d{8}$/', $period)) {
            throw new InvalidArgumentException("Invalid DHIS2 period format '{$period}'. Expected YYYYMM, YYYYWW, or YYYYMMDD.");
        }

        $formattedValues = [];
        foreach ($dataValues as $dv) {
            if ($dv['dataElement'] === '') {
                continue;
            }

            $row = [
                'dataElement' => $dv['dataElement'],
                'categoryOptionCombo' => (string) ($dv['categoryOptionCombo'] ?? $defaultCategoryOptionCombo),
                'value' => (string) $dv['value'],
            ];

            if (isset($dv['comment']) && $dv['comment'] !== '') {
                $row['comment'] = (string) $dv['comment'];
            }

            $formattedValues[] = $row;
        }

        return [
            'dataSet' => $dataSetUid,
            'completeDate' => $completeDate ?? date('Y-m-d'),
            'period' => $period,
            'orgUnit' => $orgUnitUid,
            'dataValues' => $formattedValues,
        ];
    }
}
