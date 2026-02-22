<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .header { text-align: center; margin-bottom: 30px; }
        .company-info { margin-bottom: 20px; }
        .invoice-info { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .total-section { margin-top: 20px; }
        .footer { margin-top: 50px; text-align: center; font-size: 10px; color: #666; }
        .dpp-row td { font-size: 10px; color: #888; }

           /* Header / Kop Surat */
    .header-section {
      text-align: center;
      margin-bottom: 20px;
      border-bottom: 2px solid #000;
      padding-bottom: 10px;
    }

    .header-section img {
      max-width: 100%;
      height: auto;
    }

    </style>
</head>
<body>

@php
    /* ── Tax Breakdown (rumus accounting Indonesia) ── */
    $subtotal     = (float) $invoice->subtotal;
    $taxRate      = (float) ($invoice->tax_percentage ?? 11);
    $dppLainnya   = round($subtotal * (11 / 12), 0);
    $ppnAmount    = round($dppLainnya * ($taxRate / 100), 0);
    $totalWithTax = $subtotal + $ppnAmount;

    $totalPaid = $invoice->payments->where('status', 'success')->sum('amount');

    $taxDeductions = 0;
    if ($invoice->relationLoaded('taxInvoices')) {
        $taxDeductions = $invoice->taxInvoices
            ->where('status', 'approved')
            ->whereIn('tax_type', ['pph_21', 'pph_22', 'pph_23'])
            ->sum('tax_amount');
    }

    $outstanding = max(0, $totalWithTax - $totalPaid);
    $netReceived = max(0, $totalPaid - $taxDeductions);
    $overpayment = max(0, $totalPaid - $totalWithTax);

    $poNumber  = $invoice->purchase_order?->po_number ?? $invoice->po_id ?? '-';
    $invoiceId = $invoice->invoice_id;
@endphp

    <!-- Logo -->
    <div class="header-section">
        @if (!empty($invoice->company->logo_path))
            <img src="{{ public_path('storage/' . $invoice->company->logo_path) }}" alt="Logo Company" style="width:800px; height:auto;">
        @endif
    </div>

    <!-- Header -->
    <div class="header">
        <h1>INVOICE</h1>
        <h2>{{ $invoice->invoice_number }}</h2>
    </div>

    <!-- Company Info -->
    <div class="company-info">
        <strong>{{ $invoice->company->company_name }}</strong><br>
        {{ $invoice->company->address }}<br>
        Phone: {{ $invoice->company->phone }}<br>
        Email: {{ $invoice->company->email }}
    </div>

    <!-- Bill To + Meta -->
    <table>
        <tr>
            <td width="50%">
                <strong>Bill To:</strong><br>
                {{ $invoice->customer->customer_name }}<br>
                {{ $invoice->customer->address }}<br>
                Phone: {{ $invoice->customer->phone }}<br>
                Email: {{ $invoice->customer->email }}
            </td>
            <td width="50%">
                <strong>ID:</strong> {{ $invoiceId }}<br>
                <strong>PO No.:</strong> {{ $poNumber }}<br>
                <strong>Invoice Date:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}<br>
                <strong>Due Date:</strong> {{ $invoice->due_date->format('d/m/Y') }}<br>
                <strong>Payment Status:</strong> {{ $invoice->payment_status_label }}<br>
                <strong>Currency:</strong> {{ $invoice->currency }}
            </td>
        </tr>
    </table>

    <!-- Items — No, Kode, Brand, Nama Barang, Jumlah, Harga, Diskon, Sub Total -->
    <table>
        <thead>
            <tr>
                <th width="4%">No</th>
                <th width="10%">Kode</th>
                <th width="10%">Brand</th>
                <th width="28%">Nama Barang</th>
                <th class="text-center" width="8%">Jumlah</th>
                <th class="text-center" width="6%">Sat.</th>
                <th class="text-right" width="13%">Harga</th>
                <th class="text-right" width="8%">Diskon</th>
                <th class="text-right" width="13%">Sub Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $index => $item)
            @php
                $unitPrice = (float) $item->unit_price;
                $qty       = (float) $item->quantity;
                $diskonPct = (float) ($item->discount_percent ?? 0);
                $diskonAmt = $unitPrice * $diskonPct / 100;
                $lineTotal = ($unitPrice - $diskonAmt) * $qty;
                $kode      = $item->product?->product_code ?? '-';
                $brand     = $item->product?->brand ?? '-';
            @endphp
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $kode }}</td>
                <td>{{ $brand }}</td>
                <td>
                    <strong>{{ $item->product_name }}</strong>
                    @if(!empty($item->product_description))
                        <br><small style="color:#888;">{{ $item->product_description }}</small>
                    @endif
                    @if(!empty($item->notes))
                        <br><small>{{ $item->notes }}</small>
                    @endif
                </td>
                <td class="text-center">{{ number_format($qty, 0, ',', '.') }}</td>
                <td class="text-center">{{ $item->unit }}</td>
                <td class="text-right">{{ number_format($unitPrice, 0, ',', '.') }}</td>
                <td class="text-right">{{ $diskonPct > 0 ? $diskonPct . '%' : '-' }}</td>
                <td class="text-right">{{ number_format($lineTotal, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Total Section -->
    <div class="total-section">
        <table width="40%" style="float: right;">
            <tr>
                <td><strong>Sub Total:</strong></td>
                <td class="text-right">{{ $invoice->currency }} {{ number_format($subtotal, 0, ',', '.') }}</td>
            </tr>

            @if($invoice->discount_amount > 0)
            <tr>
                <td><strong>Diskon:</strong></td>
                <td class="text-right" style="color:red;">- {{ $invoice->currency }} {{ number_format($invoice->discount_amount, 0, ',', '.') }}</td>
            </tr>
            @endif

            <tr class="dpp-row">
                <td>DPP Lainnya (Konversi 11/12):</td>
                <td class="text-right">{{ $invoice->currency }} {{ number_format($dppLainnya, 0, ',', '.') }}</td>
            </tr>

            <tr>
                <td><strong>Total Pajak (PPN {{ $taxRate }}%):</strong></td>
                <td class="text-right">{{ $invoice->currency }} {{ number_format($ppnAmount, 0, ',', '.') }}</td>
            </tr>

            <tr style="background-color: #f2f2f2;">
                <td><strong>Total Penjualan + Pajak:</strong></td>
                <td class="text-right"><strong>{{ $invoice->currency }} {{ number_format($totalWithTax, 0, ',', '.') }}</strong></td>
            </tr>

            @if($taxDeductions > 0)
            <tr>
                <td><strong>Potongan PPh (Bukti Potong):</strong></td>
                <td class="text-right" style="color:red;">- {{ $invoice->currency }} {{ number_format($taxDeductions, 0, ',', '.') }}</td>
            </tr>
            @endif

            <tr>
                <td><strong>Dibayar:</strong></td>
                <td class="text-right" style="color: green;">- {{ $invoice->currency }} {{ number_format($totalPaid, 0, ',', '.') }}</td>
            </tr>

            @if($overpayment > 0)
            <tr>
                <td><strong>Kelebihan Bayar:</strong></td>
                <td class="text-right" style="color:blue;">+ {{ $invoice->currency }} {{ number_format($overpayment, 0, ',', '.') }}</td>
            </tr>
            @endif

            <tr>
                <td><strong>Total Harus Dibayar:</strong></td>
                <td class="text-right" style="color: red;"><strong>{{ $invoice->currency }} {{ number_format($outstanding, 0, ',', '.') }}</strong></td>
            </tr>

            <tr style="background-color: #f0fdf4;">
                <td><strong>Diterima Bersih:</strong></td>
                <td class="text-right" style="color:green;"><strong>{{ $invoice->currency }} {{ number_format($netReceived, 0, ',', '.') }}</strong></td>
            </tr>
        </table>
    </div>

    <div style="clear: both;"></div>

    <!-- Notes -->
    @if($invoice->notes)
    <div style="margin-top: 30px;">
        <strong>Notes:</strong><br>
        {{ $invoice->notes }}
    </div>
    @endif

    @if($invoice->payment_terms)
    <div style="margin-top: 10px;">
        <strong>Payment Terms:</strong> {{ $invoice->payment_terms }}
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p>Thank you for your business!</p>
        <p>Generated on {{ now()->format('d/m/Y H:i') }}</p>
    </div>

</body>
</html>