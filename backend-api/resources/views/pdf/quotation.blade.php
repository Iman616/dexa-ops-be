<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation - {{ $quotation->quotation_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Times New Roman', Times, serif;
            font-size: 11pt;
            line-height: 1.5;
            padding: 30px 40px;
            color: #000;
        }

        .document-title {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            margin-bottom: 25px;
            letter-spacing: 3px;
        }

        .header-info {
            width: 100%;
            margin-bottom: 20px;
        }

        .header-info table {
            width: 100%;
        }

        .header-info td {
            padding: 2px 0;
            vertical-align: top;
            font-size: 10pt;
        }

        .header-left {
            width: 60%;
        }

        .header-right {
            width: 40%;
            text-align: right;
        }

        .recipient-section {
            margin: 25px 0 20px 0;
        }

        .recipient-section table {
            width: 100%;
        }

        .recipient-section td {
            padding: 2px 0;
            vertical-align: top;
            font-size: 10pt;
        }

        .greeting {
            margin: 20px 0 10px 0;
            font-size: 10pt;
        }

        .intro-text {
            margin: 10px 0 20px 0;
            text-align: justify;
            font-size: 10pt;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0 10px 0;
        }

        .items-table th {
            background-color: #ffffff;
            border: 1px solid #000;
            padding: 6px 4px;
            text-align: center;
            font-weight: bold;
            font-size: 9pt;
            vertical-align: middle;
        }

        .items-table td {
            border: 1px solid #000;
            padding: 6px 4px;
            font-size: 9pt;
            vertical-align: top;
        }

        .items-table tbody tr {
            page-break-inside: avoid;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .summary-row {
            border-left: none;
            border-right: 1px solid #000;
            border-top: none;
            border-bottom: 1px solid #000;
            text-align: right;
            font-weight: bold;
            padding-right: 10px;
        }

        .summary-value {
            border: 1px solid #000;
            text-align: right;
            padding-right: 10px;
        }

        .no-border-left {
            border-left: none;
        }

        .terms-section {
            margin-top: 20px;
            page-break-inside: avoid;
        }

        .terms-section p {
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 10pt;
            text-decoration: underline;
        }

        .terms-section ol {
            margin-left: 20px;
            font-size: 10pt;
        }

        .terms-section li {
            margin-bottom: 6px;
            text-align: justify;
            line-height: 1.4;
        }

        .closing-text {
            margin-top: 20px;
            font-size: 10pt;
        }

        .signature-section {
            margin-top: 35px;
            width: 100%;
        }

        .signature-box {
            float: right;
            width: 200px;
            text-align: center;
        }

        .signature-box .hormat-kami {
            font-size: 10pt;
            margin-bottom: 50px;
        }

        .signature-box .signature-stamp {
            min-height: 50px;
            margin-bottom: 5px;
        }

        .signature-box .name {
            font-weight: bold;
            font-size: 10pt;
            text-decoration: underline;
            margin-bottom: 2px;
        }

        .signature-box .position {
            font-size: 10pt;
        }

        .clearfix::after {
            content: "";
            display: table;
            clear: both;
        }

        @page {
            margin: 1.5cm 2cm;
        }
    </style>
</head>
<body>
    <!-- Document Title -->
    <div class="document-title">QUOTATION</div>

    <!-- Header Information -->
    <div class="header-info">
        <table>
            <tr>
                <td class="header-left">
                    <strong>No Quotation : {{ $quotation->quotation_number }}</strong>
                </td>
                <td class="header-right">
                    Date : {{ $quotation->quotation_date->format('d F Y') }}
                </td>
            </tr>
            <tr>
                <td class="header-left"></td>
                <td class="header-right">
                    Admin : {{ $quotation->createdByUser->username ?? 'Admin' }}
                </td>
            </tr>
            <tr>
                <td class="header-left"></td>
                <td class="header-right">
                    No. Hp : {{ $quotation->customer->phone ?? '0821 13909351' }}
                </td>
            </tr>
        </table>
    </div>

    <!-- Recipient Section -->
    <div class="recipient-section">
        <table>
            <tr>
                <td><strong>Kepada Yth.</strong></td>
            </tr>
            <tr>
                <td><strong>{{ $quotation->customer->customer_name }}</strong></td>
            </tr>
            <tr>
                <td><strong>UP : {{ $quotation->customer->contact_person ?? 'Ibu Vany Suryaningsih' }}</strong></td>
            </tr>
        </table>
    </div>

    <!-- Greeting -->
    <div class="greeting">Dengan hormat,</div>
    
    <!-- Introduction -->
    <div class="intro-text">
        Bersama dengan ini kami sampaikan penawaran harga untuk item yang dibutuhkan dengan spesifikasi sebagai berikut :
    </div>

    <!-- Items Table -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 4%;">No</th>
                <th style="width: 28%;">Nama Barang</th>
                <th style="width: 16%;">Spesifikasi</th>
                <th style="width: 10%;">Jumlah<br/>Kebutuhan</th>
                <th style="width: 14%;">Harga Satuan</th>
                <th style="width: 14%;">Total Harga</th>
                <th style="width: 14%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->items as $index => $item)
            @php
                $itemSubtotal = $item->quantity * $item->unit_price;
            @endphp
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>{{ $item->product_name }}</td>
                <td>{{ $item->product->product_code ?? '-' }}</td>
                <td class="text-center">{{ number_format($item->quantity, 0) }}&nbsp;&nbsp;&nbsp;{{ $item->product->unit ?? 'btl' }}</td>
                <td class="text-right">Rp&nbsp;&nbsp;&nbsp;{{ number_format($item->unit_price, 0, ',', '.') }}</td>
                <td class="text-right">Rp&nbsp;&nbsp;&nbsp;{{ number_format($itemSubtotal, 0, ',', '.') }}</td>
                <td class="text-center">READY STOCK</td>
            </tr>
            @endforeach
            
            <!-- Summary Rows Inside Table -->
            <tr>
                <td colspan="5" class="summary-row">Subtotal</td>
                <td class="summary-value">Rp&nbsp;&nbsp;&nbsp;{{ number_format($subtotal, 0, ',', '.') }}</td>
                <td class="no-border-left" style="border: none;"></td>
            </tr>
            <tr>
                <td colspan="5" class="summary-row">DPP Nilai Lain (Konversi 11/12)</td>
                <td class="summary-value">Rp&nbsp;&nbsp;&nbsp;{{ number_format($dpp, 0, ',', '.') }}</td>
                <td class="no-border-left" style="border: none;"></td>
            </tr>
            <tr>
                <td colspan="5" class="summary-row">PPN</td>
                <td class="summary-value">Rp&nbsp;&nbsp;&nbsp;{{ number_format($ppn, 0, ',', '.') }}</td>
                <td class="no-border-left" style="border: none;"></td>
            </tr>
            <tr>
                <td colspan="5" class="summary-row" style="font-weight: bold;">Total</td>
                <td class="summary-value" style="font-weight: bold;">Rp&nbsp;&nbsp;&nbsp;{{ number_format($total, 0, ',', '.') }}</td>
                <td class="no-border-left" style="border: none;"></td>
            </tr>
        </tbody>
    </table>

    <!-- Terms and Conditions -->
    <div class="terms-section">
        <p>Syarat dan Ketentuan :</p>
        <ol>
            <li>Dengan terbitnya Surat Pesanan atau Surat Perintah Kerja, kami anggap telah mengerti dan menyetujui segala informasi produk yang tercantum dalam Quotation.</li>
            <li>Item ready stock tidak mengikat.</li>
            <li>Kondisi lamanya waktu indent dapat berubah-ubah sesuai dengan kondisi dari prinsipal dan kendala lainnya.</li>
            <li>Berdasarkan PMK No. 131 PPN 12% x 11/12 x Harga Jual</li>
        </ol>
    </div>

    <!-- Closing -->
    <div class="closing-text">
        Demikian penawaran kami, atas perhatian dan kerjasamanya kami ucapkan terima kasih.
    </div>

    <!-- Signature -->
    <div class="signature-section clearfix">
        <div class="signature-box">
            <div class="hormat-kami">Hormat Kami,</div>
            <div class="signature-stamp">
                @if($quotation->status === 'approved' && $quotation->signed_at)
                <!-- Tampilkan stamp atau indicator approved -->
               
                @endif
            </div>
            <div class="name">
                @if($quotation->status === 'approved' && $quotation->signed_name)
                    {{ $quotation->signed_name }}
                @else
                    {{ $quotation->createdByUser->full_name ?? 'Cahyana Supriadi, S.Tp' }}
                @endif
            </div>
            <div class="position">
                @if($quotation->status === 'approved' && $quotation->signed_position)
                    {{ $quotation->signed_position }}
                @else
                    Direktur
                @endif
            </div>
        </div>
    </div>
</body>
</html>
