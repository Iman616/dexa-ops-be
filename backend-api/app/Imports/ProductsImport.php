<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Supplier;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Validators\Failure;
use Throwable;

class ProductsImport implements 
    ToModel, 
    WithHeadingRow, 
    WithValidation, 
    WithBatchInserts, 
    WithChunkReading,
    SkipsOnError,
    SkipsOnFailure
{
    use Importable;

    protected $errors = [];
    protected $failures = [];
    protected $imported = 0;
    protected $skipped = 0;

    /**
     * @param array $row
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function model(array $row)
    {
        // Skip empty rows
        if (empty($row['product_code']) || empty($row['product_name'])) {
            $this->skipped++;
            return null;
        }

        // Find supplier by name or code
        $supplierId = null;
        if (!empty($row['supplier'])) {
            $supplier = Supplier::where('supplier_name', 'like', '%' . $row['supplier'] . '%')
                               ->orWhere('supplier_code', $row['supplier'])
                               ->first();
            $supplierId = $supplier ? $supplier->supplier_id : null;
        }

        // Map product type from label to code
        $productType = $this->mapProductType($row['product_type'] ?? null);

        $this->imported++;

        return new Product([
            'product_code'    => $row['product_code'],
            'product_name'    => $row['product_name'],
            'category'        => $row['category'] ?? null,
            'product_type'    => $productType,
            'brand'           => $row['brand'] ?? 'Unknown',
            'unit'            => $row['unit'] ?? 'pcs',
            'supplier_id'     => $supplierId,
            'purchase_price'  => $this->cleanPrice($row['purchase_price'] ?? 0),
            'selling_price'   => $this->cleanPrice($row['selling_price'] ?? 0),
            'is_precursor'    => $this->parseBoolean($row['is_precursor'] ?? false),
            'description'     => $row['description'] ?? null,
        ]);
    }

    /**
     * Validation rules
     */
    public function rules(): array
    {
        return [
            'product_code' => 'required|string|max:100|unique:products,product_code',
            'product_name' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'product_type' => 'nullable|string',
            'brand' => 'required|string|max:200',
            'unit' => 'nullable|string|max:50',
            'supplier' => 'nullable|string',
            'purchase_price' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'is_precursor' => 'nullable',
            'description' => 'nullable|string',
        ];
    }

    /**
     * Custom validation messages
     */
    public function customValidationMessages()
    {
        return [
            'product_code.required' => 'Kode produk wajib diisi',
            'product_code.unique' => 'Kode produk sudah ada di database',
            'product_name.required' => 'Nama produk wajib diisi',
            'brand.required' => 'Brand wajib diisi',
        ];
    }

    /**
     * Handle errors
     */
    public function onError(Throwable $e)
    {
        $this->errors[] = $e->getMessage();
        $this->skipped++;
    }

    /**
     * Handle validation failures
     */
    public function onFailure(Failure ...$failures)
    {
        foreach ($failures as $failure) {
            $this->failures[] = [
                'row' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ];
            $this->skipped++;
        }
    }

    /**
     * Batch size for bulk insert
     */
    public function batchSize(): int
    {
        return 100;
    }

    /**
     * Chunk size for memory efficiency
     */
    public function chunkSize(): int
    {
        return 100;
    }

    /**
     * Get import summary
     */
    public function getSummary()
    {
        return [
            'imported' => $this->imported,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'failures' => $this->failures,
        ];
    }

    /* ================= HELPER METHODS ================= */

    /**
     * Clean price string (remove Rp, commas, dots)
     */
    private function cleanPrice($price)
    {
        if (is_numeric($price)) {
            return (int) $price;
        }

        // Remove currency symbols, commas, dots
        $cleaned = preg_replace('/[^0-9]/', '', $price);
        return (int) $cleaned ?: 0;
    }

    /**
     * Parse boolean from various formats
     */
    private function parseBoolean($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim($value));
        return in_array($value, ['yes', 'ya', 'true', '1', 'y']);
    }

    /**
     * Map product type label to code
     */
    private function mapProductType($type)
    {
        if (empty($type)) {
            return null;
        }

        $type = strtolower(trim($type));
        
        $mapping = [
            'prekursor' => 'prekursor',
            'precursor' => 'prekursor',
            'bbo' => 'bbo',
            'ppi' => 'ppi',
            'teknis' => 'teknis',
            'khusus' => 'teknis',
            'glassware' => 'glassware',
            'mudah pecah' => 'glassware',
            'alat lab' => 'alat_lab',
            'alat' => 'alat_lab',
        ];

        foreach ($mapping as $key => $value) {
            if (str_contains($type, $key)) {
                return $value;
            }
        }

        return null;
    }
}