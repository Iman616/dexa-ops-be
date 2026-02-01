<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kwitansi {{ $receipt->receipt_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Arial', sans-serif;
            font-size: 12px;
            line-height: 1.6;
            color: #333;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            border: 2px solid #000;
            padding: 30px;
        }

        .header {
            text-align: center;
            border-bottom: 3px double #000;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }

        .header h1 {
            font-size: 24px;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .header .company-name {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .header .company-info {
            font-size: 11px;
            color: #666;
        }

        .receipt-title {
            text-align: center;
            margin: 20px 0;
        }

        .receipt-title h2 {
            font-size: 20px;
            text-transform: uppercase;
            letter-spacing: 3px;
            margin-bottom: 5px;
        }

        .receipt-title .receipt-number {
            font-size: 12px;
            color: #666;
        }

        .content {
            margin: 30px 0;
        }

        .row {
            display: table;
            width: 100%;
            margin-bottom: 15px;
        }

        .row .label {
            display: table-cell;
            width: 200px;
            font-weight: bold;
            vertical-align: top;
            padding-right: 10px;
        }

        .row .separator {
            display: table-cell;
            width: 20px;
            vertical-align: top;
        }

        .row .value {
            display: table-cell;
            vertical-align: top;
        }

        .amount {
            font-size: 16px;
            font-weight: bold;
        }

        .terbilang {
            font-style: italic;
            color: #555;
        }

        .footer {
            margin-top: 50px;
            display: table;
            width: 100%;
        }

        .footer .col {
            display: table-cell;
            width: 50%;
            text-align: center;
            vertical-align: top;
        }

        .footer .date {
            text-align: left;
            padding-left: 20px;
        }

        .signature {
            margin-top: 80px;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
        }

        .signature-name {
            font-weight: bold;
            margin-top: 5px;
        }

        .note {
            margin-top: 20px;
            font-size: 10px;
            color: #999;
            text-align: center;
        }

        @media print {
            body {
                padding: 0;
            }
            .container {
                border: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            @if($receipt->company)
                <div class="company-name">{{ $receipt->company->company_name }}</div>
                @if($receipt->company->address)
                    <div class="company-info">{{ $receipt->company->address }}</div>
                @endif
                @if($receipt->company->phone || $receipt->company->email)
                    <div class="company-info">
                        @if($receipt->company->phone)
                            Telp: {{ $receipt->company->phone }}
                        @endif
                        @if($receipt->company->phone && $receipt->company->email)
                            |
                        @endif
                        @if($receipt->company->email)
                            Email: {{ $receipt->company->email }}
                        @endif
                    </div>
                @endif
            @else
                <div class="company-name">PT. DEXATAMA</div>
            @endif
        </div>

        <!-- Receipt Title -->
        <div class="receipt-title">
            <h2>KWITANSI</h2>
            <div class="receipt-number">{{ $receipt->receipt_number }}</div>
        </div>

        <!-- Content -->
        <div class="content">
            <!-- Telah Terima Dari -->
            <div class="row">
                <div class="label">Telah Terima Dari</div>
                <div class="separator">:</div>
                <div class="value">{{ $receipt->received_from }}</div>
            </div>

            <!-- Uang Sejumlah -->
            <div class="row">
                <div class="label">Uang Sejumlah</div>
                <div class="separator">:</div>
                <div class="value amount">Rp {{ number_format($receipt->amount, 0, ',', '.') }}</div>
            </div>

            <!-- Terbilang -->
            <div class="row">
                <div class="label">Terbilang</div>
                <div class="separator">:</div>
                <div class="value terbilang">{{ $receipt->amount_in_words }}</div>
            </div>

            <!-- Untuk Pembayaran -->
            <div class="row">
                <div class="label">Untuk Pembayaran</div>
                <div class="separator">:</div>
                <div class="value">{{ $receipt->payment_for }}</div>
            </div>

            <!-- Metode Pembayaran -->
            @if($receipt->payment)
            <div class="row">
                <div class="label">Metode Pembayaran</div>
                <div class="separator">:</div>
                <div class="value">{{ $receipt->payment->payment_method_label }}</div>
            </div>
            @endif

            <!-- No. Invoice -->
            @if($receipt->invoice)
            <div class="row">
                <div class="label">No. Invoice</div>
                <div class="separator">:</div>
                <div class="value">{{ $receipt->invoice->invoice_number }}</div>
            </div>
            @endif

            <!-- Catatan -->
            @if($receipt->notes)
            <div class="row">
                <div class="label">Catatan</div>
                <div class="separator">:</div>
                <div class="value">{{ $receipt->notes }}</div>
            </div>
            @endif
        </div>

        <!-- Footer -->
        <div class="footer">
            <!-- Date -->
            <div class="col date">
                <div style="margin-bottom: 10px;">
                    {{ \Carbon\Carbon::parse($receipt->receipt_date)->locale('id')->isoFormat('D MMMM Y') }}
                </div>
            </div>

            <!-- Signatures -->
            <div class="col">
                <div style="margin-bottom: 10px;">Yang Menerima,</div>
                <div class="signature" style="width: 150px; margin: 0 auto;"></div>
                <div class="signature-name">{{ $receipt->received_from }}</div>
            </div>

            <div class="col">
                <div style="margin-bottom: 10px;">Hormat Kami,</div>
                <div class="signature" style="width: 150px; margin: 0 auto;"></div>
                <div class="signature-name">
                    {{ $receipt->createdByUser->full_name ?? 'Admin' }}
                </div>
            </div>
        </div>

        <!-- Note -->
        <div class="note">
            Kwitansi ini sah dan ditandatangani secara digital oleh sistem
        </div>
    </div>
</body>
</html>
