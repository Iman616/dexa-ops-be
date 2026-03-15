<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockOpening;
use App\Models\EndingStock;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsOnError;
use Maatwebsite\Excel\Concerns\SkipsOnFailure;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Validators\Failure;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Throwable;

class StockOpeningImport implements
    ToModel,
    WithHeadingRow,
    WithValidation,
    WithBatchInserts,
    WithChunkReading,
    SkipsOnError,
    SkipsOnFailure
{
    use Importable;

    protected int  $imported  = 0;
    protected int  $skipped   = 0;
    protected array $errors   = [];
    protected array $failures = [];

    /* ─── model() ─── */
    public function model(array $row): ?StockOpening
    {
        // Skip baris kosong
        if (empty($row['product_code']) || empty($row['batch_number'])) {
            $this->skipped++;
            return null;
        }

        // Cari produk
        $product = Product::where('product_code', trim($row['product_code']))->first();
        if (!$product) {
            $this->errors[] = "Baris: produk '{$row['product_code']}' tidak ditemukan";
            $this->skipped++;
            return null;
        }

        $qty   = (int) ($row['quantity'] ?? 0);
        $value = (float) $this->cleanNumber($row['value'] ?? 0);

        if ($qty <= 0) {
            $this->errors[] = "Baris '{$row['product_code']}': quantity harus > 0";
            $this->skipped++;
            return null;
        }

        $purchasePrice = $qty > 0 ? round($value / $qty) : 0;

        $openingDate     = $this->parseDate($row['opening_date']);
        $manufactureDate = $this->parseDate($row['manufacture_date'] ?? null);
        $expiryDate      = $this->parseDate($row['expiry_date']      ?? null);

        if (!$openingDate) {
            $this->errors[] = "Baris '{$row['product_code']}': format opening_date tidak valid";
            $this->skipped++;
            return null;
        }

        // firstOrCreate batch — cegah duplikat batch_number per produk
        $batch = StockBatch::firstOrCreate(
            [
                'batch_number' => trim($row['batch_number']),
                'product_id'   => $product->product_id,
            ],
            [
                'quantity_initial'   => $qty,
                'quantity_available' => $qty,
                'purchase_price'     => $purchasePrice,
                'manufacture_date'   => $manufactureDate,
                'expiry_date'        => $expiryDate,
                'status'             => 'active',
                'notes'              => 'Import stok awal dari Excel',
            ]
        );

        // Update expiry/manufacture jika batch sudah ada tapi tanggal berbeda
        if (!$batch->wasRecentlyCreated) {
            $updates = [];
            if ($manufactureDate && !$batch->manufacture_date)
                $updates['manufacture_date'] = $manufactureDate;
            if ($expiryDate && !$batch->expiry_date)
                $updates['expiry_date'] = $expiryDate;
            if (!empty($updates))
                $batch->update($updates);
        }

        // Update ending stock setelah semua batch siap (via afterImport tidak ada di sini,
        // jadi kita lakukan per row — minor overhead tapi aman)
        $date = Carbon::parse($openingDate);
        try {
            EndingStock::updateEndingStock($batch->batch_id, $date->year, $date->month);
        } catch (\Exception $e) {
            // Non-fatal
        }

        $this->imported++;

        return new StockOpening([
            'product_id'   => $product->product_id,
            'batch_id'     => $batch->batch_id,
            'quantity'     => $qty,
            'value'        => $value,
            'opening_date' => $openingDate,
        ]);
    }

    /* ─── validasi header Excel ─── */
    public function rules(): array
    {
        return [
            'product_code'   => 'required|string',
            'batch_number'   => 'required|string|max:100',
            'quantity'       => 'required|numeric|min:1',
            'value'          => 'required|numeric|min:0',
            'opening_date'   => 'required',
            'manufacture_date' => 'nullable',
            'expiry_date'    => 'nullable',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'product_code.required' => 'Kolom product_code wajib diisi',
            'batch_number.required' => 'Kolom batch_number wajib diisi',
            'quantity.required'     => 'Kolom quantity wajib diisi',
            'quantity.min'          => 'Kolom quantity harus lebih dari 0',
            'value.required'        => 'Kolom value wajib diisi',
            'opening_date.required' => 'Kolom opening_date wajib diisi',
        ];
    }

    /* ─── error handlers ─── */
    public function onError(Throwable $e): void
    {
        $this->errors[] = $e->getMessage();
        $this->skipped++;
    }

    public function onFailure(Failure ...$failures): void
    {
        foreach ($failures as $failure) {
            $this->failures[] = [
                'row'       => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors'    => $failure->errors(),
                'values'    => $failure->values(),
            ];
            $this->skipped++;
        }
    }

    /* ─── chunk / batch ─── */
    public function batchSize(): int  { return 50; }
    public function chunkSize(): int  { return 50; }

    /* ─── summary ─── */
    public function getSummary(): array
    {
        return [
            'imported' => $this->imported,
            'skipped'  => $this->skipped,
            'errors'   => $this->errors,
            'failures' => array_map(fn($f) => [
                'row'     => $f['row'],
                'field'   => $f['attribute'],
                'message' => implode(', ', $f['errors']),
            ], $this->failures),
        ];
    }

    /* ─── helpers ─── */
    private function cleanNumber($value): float
    {
        if (is_numeric($value)) return (float) $value;
        return (float) preg_replace('/[^0-9.]/', '', str_replace(',', '', $value)) ?: 0;
    }

    private function parseDate($value): ?string
    {
        if (empty($value)) return null;

        // Jika sudah string format YYYY-MM-DD
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        // Jika serial Excel (integer)
        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)
                    ->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }

        // Format lain: d-m-Y, d/m/Y, dll
        $formats = ['d-m-Y', 'd/m/Y', 'Y/m/d', 'Y-m-d', 'd-M-Y'];
        foreach ($formats as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, trim($value))->format('Y-m-d');
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }
}
