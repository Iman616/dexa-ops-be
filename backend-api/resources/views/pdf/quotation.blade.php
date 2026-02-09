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
      line-height: 1.4;
      padding: 20px 40px;
      color: #000;
    }

    /* Header dengan Logo dan Info Perusahaan */
    .header-section {
      border-bottom: 3px solid #003399;
      padding-bottom: 10px;
      margin-bottom: 20px;
    }

    .header-content {
      display: table;
      width: 100%;
    }

    .logo-section {
      display: table-cell;
      width: 180px;
      vertical-align: middle;
      padding-right: 15px;
    }

    .logo-section img {
      max-width: 160px;
      height: auto;
    }

    .company-info {
      display: table-cell;
      vertical-align: middle;
    }

    .company-name {
      font-size: 24pt;
      font-weight: bold;
      color: #003399;
      margin-bottom: 5px;
      letter-spacing: 1px;
    }

    .company-details {
      font-size: 8.5pt;
      line-height: 1.3;
      color: #000;
    }

    .company-code {
      text-align: right;
      font-size: 9pt;
      color: #666;
      margin-top: 5px;
    }

    /* Document Title */
    .document-title {
      text-align: center;
      font-size: 14pt;
      font-weight: bold;
      margin: 25px 0 20px 0;
      letter-spacing: 2px;
    }

    /* Quotation Info */
    .quotation-info {
      width: 100%;
      margin-bottom: 15px;
    }

    .quotation-info table {
      width: 100%;
      font-size: 9.5pt;
    }

    .quotation-info td {
      padding: 1px 0;
      vertical-align: top;
    }

    .info-left {
      width: 50%;
    }

    .info-right {
      width: 50%;
      text-align: right;
    }

    /* Recipient Section */
    .recipient-section {
      margin: 20px 0 15px 0;
      font-size: 10pt;
    }

    .recipient-section p {
      margin: 2px 0;
    }

    /* Greeting */
    .greeting {
      margin: 15px 0 8px 0;
      font-size: 10pt;
    }

    /* Introduction */
    .intro-text {
      margin: 8px 0 15px 0;
      text-align: justify;
      font-size: 10pt;
      line-height: 1.4;
    }

    /* Items Table - MODIFIED VERSION */
    .items-table {
      width: 100%;
      border-collapse: collapse;
      margin: 15px 0;
      font-size: 9pt;
      border: 1px solid #000;
    }

    .items-table th {
      background-color: #ffffff;
      border: 1px solid #000;
      padding: 8px 5px;
      text-align: center;
      font-weight: bold;
      font-size: 9pt;
      vertical-align: middle;
      line-height: 1.2;
    }

    .items-table td {
      border: 1px solid #000;
      padding: 6px 8px;
      font-size: 9pt;
      vertical-align: middle;
    }

    .text-right {
      text-align: right;
    }

    .text-center {
      text-align: center;
    }

    /* Summary Rows - MODIFIED */
    .summary-row {
      border: 1px solid #000;
      text-align: right;
      font-weight: normal;
      padding-right: 10px;
       font-weight: bold;
    }

    .summary-value {
      border: 1px solid #000;
      text-align: right;
      padding-right: 10px;
             font-weight: bold;

    }

    .total-row td {
      font-weight: bold;
    }

    .summary-blank {
      border: 1px solid #000;
    }

    /* Terms Section */
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
      font-size: 9.5pt;
    }

    .terms-section li {
      margin-bottom: 5px;
      text-align: justify;
      line-height: 1.3;
    }

    /* Closing Text */
    .closing-text {
      margin-top: 15px;
      font-size: 10pt;
    }

    /* Signature Section */
    .signature-section {
      margin-top: 30px;
      width: 100%;
    }

    .signature-box {
      float: left;
      width: 80px;
      text-align: center;
    }

    .signature-box .hormat-kami {
      font-size: 10pt;
      margin-bottom: 10px;
    }

    .signature-box .signature-stamp {
      min-height: 80px;
      margin-bottom: 5px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .signature-box .signature-stamp img {
      max-width: 250px;
      max-height: 80px;
      object-fit: cover;
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
  <!-- Header dengan Logo dan Info Perusahaan -->
  <div style="text-align:center; margin-bottom:15px;">
  @if ($quotation->company->logo_path)
    <img
      src="{{ public_path('storage/' . $quotation->company->logo_path) }}"
      style="width:800px; height:auto;"
      alt="Logo {{ $quotation->company->company_name }}"
    >
  @endif
</div>


  <!-- Document Title -->
  <div class="document-title">QUOTATION</div>

  <!-- Quotation Info -->
  <div class="quotation-info">
    <table>
      <tr>
        <td class="info-left">
          <strong>No Quotation : {{ $quotation->quotation_number }}</strong>
        </td>
        <td class="info-right">
          Date : {{ $quotation->quotation_date->format('d F Y') }}
        </td>
      </tr>
      <tr>
        <td class="info-left"></td>
        <td class="info-right">
          Admin : {{ $quotation->createdByUser->username ?? 'Admin' }}
        </td>
      </tr>
      <tr>
        <td class="info-left"></td>
        <td class="info-right">
          No. Hp : {{ $quotation->customer->phone ?? '0821 13909351' }}
        </td>
      </tr>
    </table>
  </div>

  <!-- Recipient Section -->
  <div class="recipient-section">
    <p>Kepada Yth.</p>
    <p><strong>{{ $quotation->customer->customer_name }}</strong></p>
    <p><strong>UP : {{ $quotation->customer->contact_person ?? '-' }}</strong></p>
  </div>

  <!-- Greeting -->
  <div class="greeting">Dengan hormat,</div>

  <!-- Introduction -->
  <div class="intro-text">
    Bersama dengan ini kami sampaikan penawaran harga untuk item yang dibutuhkan dengan spesifikasi sebagai berikut :
  </div>

  <!-- Items Table - MODIFIED STRUCTURE -->
  <table class="items-table">
    <thead>
     <tr style="background-color: rgba(242, 242, 242, 1);">
  <th rowspan="2" style="width: 4%;">No</th>
  <th rowspan="2" style="width: 28%;">Nama Barang</th>
  <th rowspan="2" style="width: 16%;">Spesifikasi</th>
  <th colspan="2" style="width: 14%;">Jumlah Kebutuhan</th>
  <th rowspan="2" style="width: 14%;">Harga Satuan</th>
  <th rowspan="2" style="width: 14%;">Total Harga</th>
  <th rowspan="2" style="width: 10%;">Status</th>
</tr>

      <tr>
        <th style="width: 7%;">Qty</th>
        <th style="width: 7%;">Satuan</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($quotation->items as $index => $item)
        @php
          $itemSubtotal = $item->quantity * $item->unit_price;
        @endphp
        <tr class="item-row">
          <td class="text-center">{{ $index + 1 }}</td>
          <td>{{ $item->product_name }}</td>
          <td>Himedia {{ $item->product->brand ?? '-' }}</td>
          <td class="text-center">{{ number_format($item->quantity, 0) }}</td>
          <td class="text-center">{{ $item->product->unit ?? 'btl' }}</td>
          <td class="text-right">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
          <td class="text-right">Rp {{ number_format($itemSubtotal, 0, ',', '.') }}</td>
          <td class="text-center">READY STOCK</td>
        </tr>
      @endforeach

      <!-- Summary Rows -->
      <tr>
        <td colspan="6" class="summary-row">Subtotal</td>
        <td class="summary-value">Rp {{ number_format($subtotal, 0, ',', '.') }}</td>
        <td class="summary-blank"></td>
      </tr>
      <tr>
        <td colspan="6" class="summary-row">DPP Nilai Lain (Konversi 11/12)</td>
        <td class="summary-value">Rp {{ number_format($dpp, 0, ',', '.') }}</td>
        <td class="summary-blank"></td>
      </tr>
      <tr>
        <td colspan="6" class="summary-row">PPN</td>
        <td class="summary-value">Rp {{ number_format($ppn, 0, ',', '.') }}</td>
        <td class="summary-blank"></td>
      </tr>
      <tr class="total-row">
        <td colspan="6" class="summary-row">TOTAL</td>
        <td class="summary-value">Rp {{ number_format($total, 0, ',', '.') }}</td>
        <td class="summary-blank"></td>
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

   <div class="signature-section clearfix">
    <div class="signature-box">
      <div class="hormat-kami">Hormat Kami,</div>
      <div class="signature-stamp">
        @if (in_array($quotation->status, ['sent', 'issued', 'approved']) && !empty($quotation->signature_image))
          @php
            $signaturePath = storage_path('app/public/' . $quotation->signature_image);
          @endphp
          @if (file_exists($signaturePath))
            <img src="{{ $signaturePath }}" alt="Signature">
          @endif
        @endif
      </div>
      <div class="name">
        @if(in_array($quotation->status, ['sent', 'issued', 'approved']) && $quotation->signed_name)
          {{ $quotation->signed_name }}
        @else
          _________________
        @endif
      </div>
      <div class="position">
        @if(in_array($quotation->status, ['sent', 'issued', 'approved']) && $quotation->signed_position)
          {{ $quotation->signed_position }}
        @else
          Direktur
        @endif
      </div>
    </div>
  </div>
</body>

</html>