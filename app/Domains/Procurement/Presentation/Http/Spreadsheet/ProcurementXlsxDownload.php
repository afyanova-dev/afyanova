<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ProcurementXlsxDownload
{
    /**
     * @param  array<string, list<list<string>>>  $sheets
     */
    public function response(string $filename, array $sheets): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $firstSheet = true;

        foreach ($sheets as $title => $rows) {
            $sheet = $firstSheet ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $firstSheet = false;
            $sheet->setTitle($title);

            foreach ($rows as $rowIndex => $row) {
                foreach ($row as $columnIndex => $value) {
                    $sheet->setCellValueExplicit(
                        [$columnIndex + 1, $rowIndex + 1],
                        $value,
                        DataType::TYPE_STRING,
                    );
                }
            }

            $sheet->freezePane('A2');
            $sheet->getStyle('1:1')->getFont()->setBold(true);
            $sheet->setAutoFilter($sheet->calculateWorksheetDimension());
        }

        return response()->streamDownload(
            static function () use ($spreadsheet): void {
                (new Xlsx($spreadsheet))->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
