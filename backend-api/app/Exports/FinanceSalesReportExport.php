<?php

namespace App\Exports;

use App\Exports\FinanceSalesMainSheet;
use App\Http\Controllers\Api\FinanceSalesReportController;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class FinanceSalesReportExport implements WithMultipleSheets
{
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function sheets(): array
    {
        $ctrl = app(FinanceSalesReportController::class);

        // ✅ Method sudah ada, tidak akan error lagi
        [$rows, $summary, $period] = $ctrl->buildSalesReportData($this->request);

        return [
            new FinanceSalesMainSheet($rows, $summary, $period),
        ];
    }
}
