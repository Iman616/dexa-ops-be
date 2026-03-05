<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <title>Quotation - {{ $quotation->quotation_number }}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
      font-family: 'Times New Roman', Times, serif;
      font-size: 11pt;
      line-height: 1.4;
      padding: 20px 40px;
      color: #000;
    }
    .document-title {
      text-align: center;
      font-size: 14pt;
      font-weight: bold;
      margin: 25px 0 20px 0;
      letter-spacing: 2px;
    }
    .quotation-info { width: 100%; margin-bottom: 15px; }
    .quotation-info table { width: 100%; font-size: 9.5pt; }
    .quotation-info td { padding: 1px 0; vertical-align: top; }
    .info-left { width: 50%; }
    .info-right { width: 50%; text-align: right; }
    .recipient-section { margin: 20px 0 15px 0; font-size: 10pt; }
    .recipient-section p { margin: 2px 0; }
    .greeting { margin: 15px 0 8px 0; font-size: 10pt; }
    .intro-text { margin: 8px 0 15px 0; text-align: justify; font-size: 10pt; }

    /* Items Table */
    .items-table {
      width: 100%;
      border-collapse: collapse;
      margin: 15px 0;
      font-size: 8.5pt;
    }
    .items-table th {
      background-color: #f2f2f2;
      border: 1px solid #000;
      padding: 6px 4px;
      text-align: center;
      font-weight: bold;
      vertical-align: middle;
      line-height: 1.2;
    }
    .items-table td {
      border: 1px solid #000;
      padding: 5px 6px;
      vertical-align: middle;
    }
    .text-right  { text-align: right; }
    .text-center { text-align: center; }

    /* Summary */
    .summary-row  { text-align: right; font-weight: bold; padding-right: 8px; }
    .summary-value { text-align: right; font-weight: bold; padding-right: 8px; }
    .total-row td { font-weight: bold; background-color: #f9f9f9; }

    /* Terms */
    .terms-section { margin-top: 20px; page-break-inside: avoid; }
    .terms-section p { font-weight: bold; margin-bottom: 8px; font-size: 10pt; text-decoration: underline; }
    .terms-section ol { margin-left: 20px; font-size: 9.5pt; }
    .terms-section li { margin-bottom: 5px; text-align: justify; line-height: 1.3; }

    .closing-text { margin-top: 15px; font-size: 10pt; }

    /* Signature */
    .signature-section { margin-top: 30px; }
    .signature-box { display: inline-block; text-align: center; }
    .hormat-kami { font-size: 10pt; margin-bottom: 10px; }
    .signature-stamp { min-height: 80px; margin-bottom: 5px; }
    .signature-stamp img { max-width: 200px; max-height: 80px; }
    .sig-name { font-weight: bold; font-size: 10pt; text-decoration: underline; }
    .sig-position { font-size: 10pt; }

    @page { margin: 1.5cm 2cm; }
  </style>
</head>
<body>

  {{-- Logo --}}
  @if ($quotation->company->logo_path)
  <div style="text-align:center; margin-bottom:15px;">
    <img
      src="{{ public_path('storage/' . $quotation->company->logo_path) }}"
      style="max-width:700px; height:auto;"
      alt="Logo {{ $quotation->company->company_name }}"
    >
  </div>
  @endif

  {{-- Title --}}
  <div class="document-title">QUOTATION</div>

  {{-- Quotation Info --}}
  <div class="quotation-info">
    <table>
      <tr>
        <td class="info-left"><strong>No Quotation : {{ $quotation->quotation_number }}</strong></td>
        <td class="info-right">Date : {{ $quotation->quotation_date->format('d F Y') }}</td>
      </tr>
      <tr>
        <td class="info-left"></td>
        <td class="info-right">Admin : {{ $quotation->createdByUser->full_name ?? 'Admin' }}</td>
      </tr>
      <tr>
        <td class="info-left"></td>
        <td class="info-right">No. Hp : {{ $quotation->customer->phone ?? '-' }}</td>
      </tr>
    </table>
  </div>

  {{-- Recipient --}}
  <div class="recipient-section">
    <p>Kepada Yth.</p>
    <p><strong>{{ $quotation->customer->customer_name }}</strong></p>
    <p><strong>UP : {{ $quotation->customer->contact_person ?? '-' }}</strong></p>
  </div>

  <div class="greeting">Dengan hormat,</div>
  <div class="intro-text">
    Bersama dengan ini kami sampaikan penawaran harga untuk item yang dibutuhkan dengan spesifikasi sebagai berikut :
  </div>

  {{-- Items Table --}}
  {{-- Kolom: No | Nama Barang | Brand | Katalog/Kode | Qty | Satuan | Harga Satuan | Total Harga | Status --}}
  {{-- Total: 9 kolom → summary colspan="7" (sebelum kolom Total Harga + Status) --}}
  <table class="items-table">
    <thead>
      <tr>
        <th rowspan="2" style="width:4%;">No</th>
        <th rowspan="2" style="width:22%;">Nama Barang</th>
        <th rowspan="2" style="width:11%;">Brand</th>
        <th rowspan="2" style="width:13%;">Katalog/Kode</th>
        <th colspan="2" style="width:13%;">Jumlah Kebutuhan</th>
        <th rowspan="2" style="width:14%;">Harga Satuan</th>
        <th rowspan="2" style="width:13%;">Total Harga</th>
        <th rowspan="2" style="width:10%;">Status</th>
      </tr>
      <tr>
        <th style="width:6%;">Qty</th>
        <th style="width:7%;">Satuan</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($quotation->items as $index => $item)
      @php
        $itemSubtotal = (float)$item->quantity * (float)$item->unit_price;
        $brand        = $item->product?->brand       ?? $item->brand        ?? '-';
        $katalog      = $item->product?->product_code ?? $item->product_code ?? '-';
        $satuan       = $item->product?->unit         ?? $item->unit         ?? 'pcs';
        $deskripsi    = $item->product?->description  ?? null;
      @endphp
      <tr>
        <td class="text-center">{{ $index + 1 }}</td>
        <td>{{ $item->product_name }}</td>
        <td>{{ $brand }}</td>
        <td>
          {{ $katalog }}
          @if($deskripsi)
            <br><small style="color:#555; font-size:7.5pt;">{{ $deskripsi }}</small>
          @endif
        </td>
        <td class="text-center">{{ number_format($item->quantity, 0, ',', '.') }}</td>
        <td class="text-center">{{ $satuan }}</td>
        <td class="text-right">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
        <td class="text-right">Rp {{ number_format($itemSubtotal, 0, ',', '.') }}</td>
        <td class="text-center">{{ $item->item_status === 'ready' ? 'READY STOCK' : 'INDENT' }}</td>
      </tr>
      @endforeach

      {{-- Summary — colspan 7 = No+Nama+Brand+Katalog+Qty+Satuan+Harga Satuan --}}
      <tr>
        <td colspan="7" class="summary-row">Subtotal</td>
        <td class="summary-value">Rp {{ number_format($subtotal, 0, ',', '.') }}</td>
        <td></td>
      </tr>
      <tr>
        <td colspan="7" class="summary-row">
          DPP Nilai Lain (Konversi {{ round($taxRate) }}/{{ round($taxRate + 1) }})
        </td>
        <td class="summary-value">Rp {{ number_format($dpp, 0, ',', '.') }}</td>
        <td></td>
      </tr>
      <tr>
        <td colspan="7" class="summary-row">PPN {{ round($taxRate) }}%</td>
        <td class="summary-value">Rp {{ number_format($ppn, 0, ',', '.') }}</td>
        <td></td>
      </tr>
      <tr class="total-row">
        <td colspan="7" class="summary-row">TOTAL</td>
        <td class="summary-value">Rp {{ number_format($total, 0, ',', '.') }}</td>
        <td></td>
      </tr>
    </tbody>
  </table>

  {{-- Terms --}}
  <div class="terms-section">
    <p>Syarat dan Ketentuan :</p>
    <ol>
      <li>Dengan terbitnya Surat Pesanan atau Surat Perintah Kerja, kami anggap telah mengerti dan menyetujui segala informasi produk yang tercantum dalam Quotation.</li>
      <li>Item ready stock tidak mengikat.</li>
      <li>Kondisi lamanya waktu indent dapat berubah-ubah sesuai dengan kondisi dari prinsipal dan kendala lainnya.</li>
      <li>Berdasarkan PMK No. 131 PPN {{ round($taxRate) }}% x {{ round($taxRate) }}/{{ round($taxRate + 1) }} x Harga Jual</li>
    </ol>
  </div>

  <div class="closing-text">
    Demikian penawaran kami, atas perhatian dan kerjasamanya kami ucapkan terima kasih.
  </div>

  {{-- Signature --}}
  <div class="signature-section">
    <div class="signature-box">
      <div class="hormat-kami">Hormat Kami,</div>
      <div class="signature-stamp">
        @if (in_array($quotation->status, ['sent', 'issued', 'approved']) && !empty($quotation->signature_image))
          @php $signaturePath = storage_path('app/public/' . $quotation->signature_image); @endphp
          @if (file_exists($signaturePath))
            <img src="{{ $signaturePath }}" alt="Signature">
          @endif
        @endif
      </div>
      <div class="sig-name">
        {{ in_array($quotation->status, ['sent','issued','approved']) && $quotation->signed_name
            ? $quotation->signed_name
            : '_________________' }}
      </div>
      <div class="sig-position">
        {{ in_array($quotation->status, ['sent','issued','approved']) && $quotation->signed_position
            ? $quotation->signed_position
            : 'Direktur' }}
      </div>
    </div>
  </div>

</body>
</html>
