<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\FromCollection;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;

class StockOpeningTemplateExport implements
    FromCollection,
    WithHeadings,
    WithStyles,
    WithTitle,
    ShouldAutoSize
{
    public function collection()
    {
        return collect([
            [
                '1000BK100',
                'BATCH-CONTOH001',
                10,
                500000,
                '2026-01-01',
                '2025-06-01',
                '2028-06-01',
            ],
        ]);
    }

    public function headings(): array
    {
        return [
            'product_code *',
            'batch_number *',
            'quantity *',
            'value *',
            'opening_date *',
            'manufacture_date',
            'expiry_date',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:G1')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $sheet->getStyle('A2:G2')->applyFromArray([
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF9C4']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ]);

        $sheet->setCellValue('A4', 'PANDUAN PENGISIAN:');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setColor(new Color('C0392B'));

        $panduan = [
            5  => 'product_code     : Kode produk yang sudah ada di master produk (wajib)',
            6  => 'batch_number     : Nomor batch unik (wajib)',
            7  => 'quantity         : Jumlah stok awal, angka bulat positif (wajib)',
            8  => 'value            : Total nilai stok (qty × harga beli), tanpa titik/koma (wajib)',
            9  => 'opening_date     : Tanggal stock opening, format YYYY-MM-DD (wajib)',
            10 => 'manufacture_date : Tanggal produksi, format YYYY-MM-DD (opsional)',
            11 => 'expiry_date      : Tanggal expired, format YYYY-MM-DD (opsional)',
        ];

        foreach ($panduan as $row => $text) {
            $sheet->setCellValue("A{$row}", $text);
            $sheet->getStyle("A{$row}")->getFont()->setSize(9)->getColor()->setRGB('555555');
        }

        return [];
    }

    public function title(): string
    {
        return 'Template Import';
    }
}
