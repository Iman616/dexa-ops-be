<?php

namespace App\Exports;

use App\Models\StockOpening;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/* ══════════════════════════════════════════════════════
 * MAIN EXPORT — sheet data
 * ══════════════════════════════════════════════════════ */
class StockOpeningExport implements
    FromQuery,
    WithHeadings,
    WithMapping,
    WithStyles,
    WithTitle,
    ShouldAutoSize
{
    protected array $filters;
    private int $rowNumber = 0;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        $query = StockOpening::with(['product', 'batch']);

        if (!empty($this->filters['product_id'])) {
            $query->where('product_id', $this->filters['product_id']);
        }

        if (!empty($this->filters['period_year']) && !empty($this->filters['period_month'])) {
            $query->whereYear('opening_date', $this->filters['period_year'])
                  ->whereMonth('opening_date', $this->filters['period_month']);
        } elseif (!empty($this->filters['period_year'])) {
            $query->whereYear('opening_date', $this->filters['period_year']);
        }

        if (!empty($this->filters['search'])) {
            $s = $this->filters['search'];
            $query->whereHas('product', fn($q) =>
                $q->where('product_name', 'like', "%{$s}%")
                  ->orWhere('product_code', 'like', "%{$s}%")
            );
        }

        return $query->orderBy('opening_date', 'asc');
    }

    public function headings(): array
    {
        return [
            'No',
            'Kode Produk',
            'Nama Produk',
            'No. Batch',
            'Tanggal Opening',
            'Tanggal Manufaktur',
            'Tanggal Expired',
            'Jumlah (Qty)',
            'Nilai Total (IDR)',
            'Harga per Unit (IDR)',
        ];
    }

   public function map($opening): array
{
    $this->rowNumber++;
    $qty   = (int) ($opening->quantity ?? 0);
    $value = (float) ($opening->value  ?? 0);
    $purchasePrice = $qty > 0 ? round($value / $qty) : 0;

    // ✅ Semua akses ke batch pakai optional chaining / null coalescing
    $batch = $opening->batch; // bisa null

    return [
        $this->rowNumber,
        $opening->product->product_code ?? '-',
        $opening->product->product_name ?? '-',
        $batch?->batch_number           ?? '-',  // ✅ safe
        $opening->opening_date?->format('d-m-Y') ?? '-',
        $batch?->manufacture_date
            ? \Carbon\Carbon::parse($batch->manufacture_date)->format('d-m-Y')
            : '-',                              // ✅ safe — tidak throw kalau batch null
        $batch?->expiry_date
            ? \Carbon\Carbon::parse($batch->expiry_date)->format('d-m-Y')
            : '-',                              // ✅ safe
        $qty,
        $value,
        $purchasePrice,
    ];
}

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();

        // Header
        $sheet->getStyle('A1:J1')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E5F8A']],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical'   => Alignment::VERTICAL_CENTER,
            ],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(25);

        // Data rows
        $sheet->getStyle("A2:J{$lastRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CCCCCC']]],
        ]);

        // Alternating row color
        for ($i = 2; $i <= $lastRow; $i++) {
            if ($i % 2 === 0) {
                $sheet->getStyle("A{$i}:J{$i}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F2F6FB');
            }
        }

        // Number format — nilai IDR
        $sheet->getStyle("I2:J{$lastRow}")->getNumberFormat()->setFormatCode('"Rp "#,##0');

        // Qty center
        $sheet->getStyle("H2:H{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("A2:A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        return [];
    }

    public function title(): string
    {
        return 'Stock Opening';
    }
}







