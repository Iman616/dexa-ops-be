<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\FinanceSalesReportExport;

class FinanceSalesExportController extends Controller
{
    public function export(Request $request)
    {
        $fileName = 'Laporan-Penjualan-Finance-' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(
            new FinanceSalesReportExport($request), // period, start_date, end_date di-handle di export
            $fileName
        );
    }
}
