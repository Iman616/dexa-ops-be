<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FinanceSalesMainSheet implements FromArray, WithHeadings, WithTitle, WithStyles
{
    public function __construct(
        private array $rows,
        private array $summary,
        private array $period,
    ) {}

    public function title(): string
    {
        return 'DATA BASE LAPORAN';
    }

    public function headings(): array
    {
        return [
            'No', 'Unit', 'Perusahaan', 'Nama Project',
            'Tgl PI/Kontrak', 'Tgl Invoice',
            'Nilai Penjualan', 'DPP', 'PPN', 'PPh',
            'Biaya Lain', 'Dana Masuk', 'Tgl Dana Masuk',
            'Pembelian', 'Nama Agen', 'Tgl Pembelian',
            'Sisa Piutang', 'Margin', 'Margin %',
        ];
    }

    public function array(): array
    {
        $data = [];

        foreach ($this->rows as $r) {
            $data[] = [
                $r['no'],
                $r['unit_name'],
                $r['perusahaan'],
                $r['nama_project'],
                $r['tanggal_pi']      ?? '',
                $r['tanggal_invoice'],
                $r['nilai_penjualan'],
                $r['pajak']['dpp'],
                $r['pajak']['ppn'],
                $r['pajak']['pph'],
                $r['biaya_lain']['nominal'],
                $r['dana_masuk']['nominal'],
                $r['dana_masuk']['tanggal'] ?? '',
                $r['pembelian']['nominal'],
                $r['pembelian']['nama_agen']    ?? '',
                $r['pembelian']['tgl_pembelian'] ?? '',
                $r['sisa_piutang'],
                $r['margin'],
                $r['margin_percent'] . '%',
            ];
        }

        // Baris TOTAL di bagian bawah
        $data[] = [];
        $data[] = [
            'TOTAL', '', '', '', '', '',
            $this->summary['total_nilai_penjualan'],
            $this->summary['total_dpp'],
            $this->summary['total_ppn'],
            $this->summary['total_pph'],
            0,
            $this->summary['total_dana_masuk'],
            '',
            $this->summary['total_pembelian'],
            '', '',
            $this->summary['total_sisa_piutang'],
            $this->summary['total_margin'],
            $this->summary['margin_percent'] . '%',
        ];

        return $data;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // Header row bold + background
            1 => [
                'font'      => ['bold' => true],
                'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => 'D9E1F2']],
            ],
            // Baris TOTAL bold
            (count($this->rows) + 3) => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FFF2CC']],
            ],
        ];
    }
}
