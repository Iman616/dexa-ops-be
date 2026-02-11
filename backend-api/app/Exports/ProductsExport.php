<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class ProductsExport implements 
    FromQuery, 
    WithHeadings, 
    WithMapping, 
    WithStyles,
    WithTitle,
    ShouldAutoSize
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function query()
    {
        $query = Product::with('supplier');

        // Apply filters
        if (!empty($this->filters['search'])) {
            $query->search($this->filters['search']);
        }

        if (!empty($this->filters['category'])) {
            $query->where('category', $this->filters['category']);
        }

        if (!empty($this->filters['product_type'])) {
            $query->where('product_type', $this->filters['product_type']);
        }

        if (!empty($this->filters['brand'])) {
            $query->where('brand', $this->filters['brand']);
        }

        if (!empty($this->filters['supplier_id'])) {
            $query->where('supplier_id', $this->filters['supplier_id']);
        }

        if (isset($this->filters['is_precursor'])) {
            $query->where('is_precursor', $this->filters['is_precursor']);
        }

        return $query->orderBy('product_code', 'asc');
    }

    /**
     * Column headings
     */
    public function headings(): array
    {
        return [
            'No',
            'Kode Produk',
            'Nama Produk',
            'Kategori',
            'Tipe Produk',
            'Brand',
            'Satuan',
            'Supplier',
            'Harga Beli',
            'Harga Jual',
            'Prekursor',
            'Deskripsi',
            'Dibuat Pada',
        ];
    }

    /**
     * Map data for each row
     */
    public function map($product): array
    {
        static $rowNumber = 0;
        $rowNumber++;

        return [
            $rowNumber,
            $product->product_code,
            $product->product_name,
            $product->category,
            $product->product_type_label,
            $product->brand,
            $product->unit,
            $product->supplier ? $product->supplier->supplier_name : '-',
            $product->purchase_price,
            $product->selling_price,
            $product->is_precursor ? 'Ya' : 'Tidak',
            $product->description ?? '-',
            $product->created_at ? $product->created_at->format('d-m-Y H:i') : '-',
        ];
    }

    /**
     * Apply styles to worksheet
     */
    public function styles(Worksheet $sheet)
    {
        // Header row styling
        $sheet->getStyle('A1:M1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        // Set row height
        $sheet->getRowDimension(1)->setRowHeight(25);

        // Add borders to all data
        $lastRow = $sheet->getHighestRow();
        $sheet->getStyle("A1:M{$lastRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC'],
                ],
            ],
        ]);

        // Number format for price columns
        $sheet->getStyle("I2:J{$lastRow}")->getNumberFormat()
              ->setFormatCode('#,##0');

        return [];
    }

    /**
     * Sheet title
     */
    public function title(): string
    {
        return 'Data Produk';
    }
}