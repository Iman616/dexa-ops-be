<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\StockBatch;
use App\Models\StockOpening;

class BackfillStockOpening extends Command
{
    protected $signature   = 'stock:backfill-opening {--company_id= : Filter by company ID}';
    protected $description = 'Backfill stock_opening dari quantity_initial di stock_batches';

    public function handle()
    {
        $companyId = $this->option('company_id');

        $query = StockBatch::query();
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $total   = 0;
        $skipped = 0;

        $query->chunk(100, function ($batches) use (&$total, &$skipped) {
            foreach ($batches as $batch) {
                $exists = StockOpening::where('batch_id', $batch->batch_id)->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                if ((float) $batch->quantity_initial <= 0) {
                    $skipped++;
                    continue;
                }

                $openingDate = $batch->created_at
                    ? $batch->created_at->toDateString()
                    : now()->toDateString();

                StockOpening::create([
                    'product_id'   => $batch->product_id,
                    'batch_id'     => $batch->batch_id,
                    'quantity'     => $batch->quantity_initial,
                    'value'        => $batch->quantity_initial * (float) $batch->purchase_price,
                    'opening_date' => $openingDate,
                ]);

                $this->line("✅ batch_id={$batch->batch_id} | qty={$batch->quantity_initial} | date={$openingDate}");
                $total++;
            }
        });

        $this->newLine();
        $this->info("Selesai! Created: {$total} | Skipped: {$skipped}");
    }
}
