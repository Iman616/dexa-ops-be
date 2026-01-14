<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'product_code' => $this->product_code,
            'product_name' => $this->product_name,
            'category' => $this->category,
            'unit' => $this->unit,
            'is_precursor' => $this->is_precursor,
            'description' => $this->description,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            
            // Conditional relationships
            'stock_batches' => StockBatchResource::collection($this->whenLoaded('stockBatches')),
            'stock_ins' => StockInResource::collection($this->whenLoaded('stockIns')),
        ];
    }
}
