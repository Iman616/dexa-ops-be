<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Order - {{ $po->po_number }}</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: Arial, sans-serif;
      font-size: 9pt;
      line-height: 1.3;
      color: #000;
    }

    .container {
      padding: 15px 30px;
    }

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

    /* Title */
    .title-section {
      text-align: center;
      margin: 15px 0;
    }

    .title {
      font-size: 11pt;
      font-weight: bold;
      text-decoration: underline;
      letter-spacing: 1px;
    }

    /* Info Section - Two Columns */
    .info-section {
      margin-bottom: 20px;
    }

    .info-row {
      display: table;
      width: 100%;
      margin-bottom: 3px;
    }

    .info-left {
      display: table-cell;
      width: 50%;
      vertical-align: top;
      font-size: 9pt;
    }

    .info-right {
    display: table-cell;
    width: 50%;
    vertical-align: top;
    padding-left: 30px;
    font-size: 9pt;
    text-align: right;  
  }

    .info-label {
      display: inline-block;
      width: 110px;
      font-weight: normal;
    }

    /* Opening Text */
    .opening-text {
      margin: 12px 0;
      text-align: justify;
      line-height: 1.5;
      font-size: 9pt;
    }

    /* Items Table */
    .items-table {
      width: 100%;
      border-collapse: collapse;
      margin: 15px 0;
      font-size: 9pt;
    }

    .items-table th {
      border: 1px solid #000;
      padding: 5px 6px;
      text-align: center;
      font-weight: bold;
      background-color: #fff;
      font-size: 9pt;
    }

    .items-table td {
      border: 1px solid #000;
      padding: 5px 6px;
      vertical-align: top;
      font-size: 9pt;
    }

    .text-center {
      text-align: center;
    }

    .text-right {
      text-align: right;
    }

    .text-left {
      text-align: left;
    }

    /* Summary Rows */
    .items-table .summary-row td {
      border: 1px solid #000;
      font-weight: normal;
    }

    .items-table .total-row td {
      border: 1px solid #000;
      font-weight: bold;
    }

    /* Closing Text */
    .closing-text {
      margin: 15px 0;
      text-align: justify;
      line-height: 1.5;
      font-size: 9pt;
    }

    /* Signature */
    .signature-section {
      margin-top: 25px;
    }

    .signature-title {
      font-size: 9pt;
      margin-bottom: 5px;
    }

    .signature-image {
      margin: 10px 0;
      max-width: 150px;
      height: auto;
    }

    .signature-name {
      text-decoration: underline;
      font-weight: bold;
      font-size: 9pt;
      margin-top: 60px;
    }

    .signature-position {
      font-size: 9pt;
      margin-top: 3px;
    }
  </style>
</head>

<body>
  <div class="container">
    <!-- Header / Kop Surat dengan Border -->
    <div class="header-section">
      @if ($company->logo_path)
        <img src="{{ public_path('storage/' . $company->logo_path) }}" alt="Logo Company">
      @endif
    </div>

    <!-- Title -->
    <div class="title-section">
      <div class="title">PURCHASE ORDER</div>
    </div>

    <!-- Customer and PO Info - Two Columns Layout -->
  <!-- Customer and PO Info - Two Columns Layout -->
<div class="info-section">
  <!-- Row 1 -->
  <div class="info-row">
    <div class="info-left">
      <span class="info-label">To</span>: <strong>{{ $customer->customer_name }}</strong>
    </div>
    <div class="info-right">
      Date Purchase&nbsp;&nbsp;&nbsp;: {{ \App\Services\PurchaseOrderPdfService::formatDate($po->po_date) }}
    </div>
  </div>

  <!-- Row 2 -->
  <div class="info-row">
    <div class="info-left">
      <span class="info-label"></span>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;{{ $customer->address ?? '' }}
    </div>
    <div class="info-right">
      Number Purchase : {{ $po->po_number }}
    </div>
  </div>

  <!-- Row 3 -->
  <div class="info-row">
    <div class="info-left">
      <span class="info-label"></span>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;{{ $customer->city ?? '' }}{{ $customer->province ? ', ' . $customer->province : '' }}
    </div>
    <div class="info-right">
      &nbsp;
    </div>
  </div>

  <!-- Row 4 -->
  <div class="info-row">
    <div class="info-left">
      <span class="info-label">Up</span>: {{ $customer->contact_person ?? '-' }}
    </div>
    <div class="info-right">
      Contact person&nbsp;&nbsp;: {{ $customer->contact_person ?? '-' }}
    </div>
  </div>

  <!-- Row 5 -->
  <div class="info-row">
    <div class="info-left">
      <span class="info-label">Tlp/Fax</span>: {{ $customer->phone ?? '-' }}
    </div>
    <div class="info-right">
      Phone&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: {{ $customer->phone ?? '-' }}
    </div>
  </div>
  
  <!-- Row 6 - Phone line 2 jika ada -->
  @if ($customer->phone2)
  <div class="info-row">
    <div class="info-left">
      &nbsp;
    </div>
    <div class="info-right">
      &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;{{ $customer->phone2 }}
    </div>
  </div>
  @endif
</div>

    <!-- Opening Text -->
    <div class="opening-text">
      Dengan hormat,
    </div>

    <div class="opening-text">
      Menindaklanjuti penawaran yang sudah diberikan, maka dengan ini kami bermaksud untuk melakukan pemesanan dengan spesifikasi sebagai berikut :
    </div>

    <!-- Items Table -->
  <!-- Items Table -->
<table class="items-table">
  <thead>
    <tr>
      <th style="width: 4%;" rowspan="2">No</th>
      <th style="width: 26%;" rowspan="2">Nama Barang</th>
      <th style="width: 16%;" rowspan="2">Katalog</th>
      <th style="width: 10%;" colspan="2">Jumlah</th>
      <th style="width: 16%;" rowspan="2">Harga Satuan</th>
      <th style="width: 10%;" rowspan="2">Diskon</th>
      <th style="width: 18%;" rowspan="2">Total Harga</th>
    </tr>
    <tr>
      <th style="width: 6%; border-left: 1px solid #000;">Qty</th>
      <th style="width: 4%; border-left: 1px solid #000;">Unit</th>
    </tr>
  </thead>
  <tbody>
    @foreach ($items as $index => $item)
      <tr>
        <td class="text-center">{{ $index + 1 }}</td>
        <td class="text-left">{{ $item->product_name }}</td>
        <td class="text-left">{{ $item->specification ?? '-' }}</td>
        <td class="text-center" style="border-right: none;">{{ number_format($item->quantity, 0, ',', '.') }}</td>
        <td class="text-center" style="border-left: 1px solid #000;">{{ $item->unit }}</td>
        <td class="text-right">Rp&nbsp;&nbsp;&nbsp;&nbsp;{{ number_format($item->unit_price, 0, ',', '.') }}</td>
        <td class="text-center">{{ number_format($item->discount_percent, 1, ',', '.') }}%</td>
        <td class="text-right">Rp&nbsp;&nbsp;&nbsp;&nbsp;{{ number_format($item->total, 0, ',', '.') }}</td>
      </tr>
    @endforeach

    <!-- Summary Rows -->
    <tr class="summary-row">
      <td colspan="7" class="text-right"><strong>Subtotal</strong></td>
      <td class="text-right"><strong>Rp&nbsp;&nbsp;&nbsp;&nbsp;{{ number_format($subtotal, 0, ',', '.') }}</strong></td>
    </tr>
    <tr class="summary-row">
      <td colspan="7" class="text-right"><strong>PPN</strong></td>
      <td class="text-right"><strong>Rp&nbsp;&nbsp;&nbsp;&nbsp;{{ number_format($ppn, 0, ',', '.') }}</strong></td>
    </tr>
    <tr class="total-row">
      <td colspan="7" class="text-right"><strong>Total</strong></td>
      <td class="text-right"><strong>Rp&nbsp;&nbsp;&nbsp;&nbsp;{{ number_format($grand_total, 0, ',', '.') }}</strong></td>
    </tr>
  </tbody>
</table>


    <!-- Closing Text -->
    <div class="closing-text">
      Demikian Purchase Order ini dibuat untuk ditindaklanjuti. Atas perhatian dan kerjasamanya, kami ucapkan terima kasih.
    </div>

    <!-- Signature Section -->
    <div class="signature-section">
      <div class="signature-title">Best Regards</div>
      
      @if ($po->signature_image)
        <div style="margin-top: 10px;">
          <img 
            src="{{ public_path('storage/' . $po->signature_image) }}" 
            class="signature-image"
            alt="Signature"
          >
        </div>
      @endif

      <div class="signature-name" style="margin-top: {{ $po->signature_image ? '10px' : '60px' }};">
        {{ $po->signed_name ?? '_______________________' }}
      </div>
      
      @if ($po->signed_position)
        <div class="signature-position">
          {{ $po->signed_position }}
        </div>
      @endif
    </div>

  </div>
</body>

</html>