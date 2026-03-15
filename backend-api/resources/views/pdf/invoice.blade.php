<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 0; padding: 20px; }

        /* ── Kop Surat (hanya tampil jika PPN) ── */
        .kop-surat {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        .kop-surat img { max-width: 100%; height: auto; }

        /* ── Header Invoice ── */
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { margin: 0; font-size: 20px; }
        .header h2 { margin: 4px 0 0; font-size: 14px; font-weight: normal; }

        /* ── Tabel umum ── */
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { border: 1px solid #ddd; padding: 7px 8px; text-align: left; vertical-align: top; }
        th { background-color: #f2f2f2; font-size: 11px; }

        .text-right  { text-align: right; }
        .text-center { text-align: center; }

        /* ── Baris DPP ── */
        .dpp-row td { font-size: 10px; color: #888; }

        /* ── Total section ── */
        .total-table { width: 45%; float: right; font-size: 12px; }
        .total-table td { border: none; padding: 4px 8px; }
        .total-table .divider td { border-top: 1px solid #ccc; padding-top: 6px; }
        .total-table .highlight { background-color: #f2f2f2; }
        .total-table .highlight-green { background-color: #f0fdf4; }

        .footer { margin-top: 50px; text-align: center; font-size: 10px; color: #666; }

        /* ── Badge PPN / Non-PPN ── */
        .badge-ppn     { display: inline-block; background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: bold; }
        .badge-nonppn  { display: inline-block; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; padding: 2px 8px; border-radius: 4px; font-size: 10px; }
    </style>
</head>
<body>

@php
    /* ─────────────────────────────────────────────
     *  FLAG: apakah invoice ini menggunakan PPN?
     *  Sumber: kolom invoices.use_ppn (boolean)
     *  Default: true untuk backward compatibility
     * ───────────────────────────────────────────── */
    $usePpn   = (bool) ($invoice->use_ppn ?? true);
    $isDppNilaiLain = $usePpn; // non-PPN tidak pakai DPP Nilai Lain

    /* ── Hitung subtotal dari item (setelah diskon per-item) ── */
    $subtotal = 0;
    foreach ($invoice->items as $item) {
        $unitPrice = (float) $item->unit_price;
        $qty       = (float) $item->quantity;
        $diskonPct = (float) ($item->discount_percent ?? 0);
        $lineTotal = $unitPrice * (1 - $diskonPct / 100) * $qty;
        $subtotal += $lineTotal;
    }
    $subtotal = round($subtotal);

    /* ── Tax Calculation ── */
    $taxRate   = (float) ($invoice->tax_percentage ?? 11);

      if ($usePpn && $isDppNilaiLain) {
        $dppLainnya = round($subtotal * ($taxRate / ($taxRate + 1)));
        $ppnAmount  = round($dppLainnya * ($taxRate / 100)); // ✅ bukan ($taxRate + 1)
    } else {
        $dppLainnya = 0;
        $ppnAmount  = $usePpn ? round($subtotal * ($taxRate / 100)) : 0;
    }
    $totalWithTax = $subtotal + $ppnAmount;

    /* ── Payment & Deductions ── */
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

    $poNumber    = $invoice->purchase_order?->po_number ?? $invoice->po_id ?? '-';
    $invoiceId   = $invoice->invoice_id;
    $currency    = $invoice->currency ?? 'Rp';

    /* Helper format angka */
    $fmt = fn($n) => number_format($n, 0, ',', '.');
@endphp

{{-- ══════════════════════════════════════════════
     KOP SURAT — hanya tampil untuk invoice PPN
     ══════════════════════════════════════════════ --}}
@if($usePpn)
<div class="kop-surat">
    @if($company->logo_path)
        <img src="{{ public_path('storage/' . $company->logo_path) }}" alt="Logo {{ $company->company_name }}">
    @else
        <h2 style="margin:0;">{{ $company->company_name }}</h2>
        <p style="margin:4px 0;">{{ $company->address }}</p>
        <p style="margin:0;">{{ $company->phone }} | {{ $company->email }}</p>
    @endif
</div>
@endif

{{-- ══ Header Invoice ══ --}}
<div class="header">
    <h1>INVOICE</h1>
    <h2>{{ $invoice->invoice_number }}</h2>

</div>

{{-- ══ Company Info (non-PPN tampilkan company info di sini, bukan kop) ══ --}}
@if(!$usePpn)
<div style="margin-bottom: 16px;">
    <strong>{{ $company->company_name }}</strong><br>
    {{ $company->address }}<br>
    Phone: {{ $company->phone }} | Email: {{ $company->email }}
</div>
@endif

{{-- ══ Bill To + Meta ══ --}}
<table>
    <tr>
        <td width="50%">
            <strong>Kepada:</strong><br>
            {{ $invoice->customer->customer_name }}<br>
            {{ $invoice->customer->address }}<br>
            Telepon: {{ $invoice->customer->phone }}<br>
            Email: {{ $invoice->customer->email }}
        </td>
        <td width="50%">
            <strong>ID:</strong> {{ $invoiceId }}<br>
            <strong>No. PO:</strong> {{ $poNumber }}<br>
            <strong>Tanggal Invoice:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}<br>
            <strong>Jatuh Tempo:</strong> {{ $invoice->due_date->format('d/m/Y') }}<br>
            <strong>Status Pembayaran:</strong> {{ $invoice->payment_status_label }}<br>
            <strong>Mata Uang:</strong> {{ $currency }}
        </td>
    </tr>
</table>
{{-- ══ Items Table ══ --}}
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
            $lineTotal = round(($unitPrice - $diskonAmt) * $qty);
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
            <td class="text-center">{{ $fmt($qty) }}</td>
            <td class="text-center">{{ $item->unit }}</td>
            <td class="text-right">{{ $fmt($unitPrice) }}</td>
            <td class="text-right">{{ $diskonPct > 0 ? $diskonPct . '%' : '-' }}</td>
            <td class="text-right">{{ $fmt($lineTotal) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

{{-- ══ Total Section ══ --}}
<div class="total-section">
    <table class="total-table">

        {{-- Sub Total (setelah diskon per-item) --}}
        <tr>
            <td><strong>Sub Total</strong></td>
            <td class="text-right">: Rp &nbsp;{{ $fmt($subtotal) }}</td>
        </tr>

        {{-- DPP Nilai Lain — hanya untuk PPN --}}
        @if($usePpn && $dppLainnya > 0)
        <tr class="dpp-row">
            <td>DPP Lainnya</td>
            <td class="text-right">: Rp &nbsp;{{ $fmt($dppLainnya) }}</td>
        </tr>

        <tr>
<td><strong>Total PPN </strong></td>            <td class="text-right">: Rp &nbsp;{{ $fmt($ppnAmount) }}</td>
        </tr>
        @endif

        {{-- Total Penjualan + Pajak --}}
        <tr class="divider highlight">
            <td><strong>Total</strong></td>
            <td class="text-right"><strong>: Rp &nbsp;{{ $fmt($totalWithTax) }}</strong></td>
        </tr>

        {{-- Potongan PPh (hanya jika ada dan invoice PPN) --}}
        @if($usePpn && $taxDeductions > 0)
        <tr>
            <td><strong>Potongan PPh (Bukti Potong)</strong></td>
            <td class="text-right" style="color:red;">: - Rp &nbsp;{{ $fmt($taxDeductions) }}</td>
        </tr>
        @endif

        {{-- Dibayar --}}

        {{-- Kelebihan Bayar --}}


        {{-- Total Harus Dibayar --}}




    </table>
</div>

<div style="clear: both; padding-top: 10px;"></div>

{{-- ══ Notes & Terms ══ --}}
@if($invoice->notes)
<div style="margin-top: 20px;">
    <strong>Notes:</strong><br>{{ $invoice->notes }}
</div>
@endif

@if($invoice->payment_terms)
<div style="margin-top: 8px;">
    <strong>Payment Terms:</strong> {{ $invoice->payment_terms }}
</div>
@endif

{{-- ══ Footer ══ --}}
<div class="footer">
    <p>Thank you for your business!</p>
    <p> {{ now()->format('d/m/Y H:i') }}</p>
</div>

</body>
</html>
