<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Order Supplier - {{ $po->po_number }}</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: Arial, sans-serif;
      font-size: 10pt;
      line-height: 1.4;
      color: #000;
    }

    .container {
      padding: 20px 35px;
    }

    /* Header Section - Kop Surat */
    .header-section {
      margin-bottom: 10px;
    }

    .header-content {
      position: relative;
      min-height: 120px;
    }

    .logo-section {
      position: absolute;
      left: 0;
      top: 0;
      width: 140px;
    }

    .company-logo {
      width: 130px;
      height: auto;
      display: block;
    }

    /* Title */
    .title-section {
      text-align: center;
      margin: 20px 0 15px 0;
    }

    .title {
      font-size: 12pt;
      font-weight: bold;
      text-decoration: underline;
      letter-spacing: 2px;
    }

    /* Supplier and PO Info Section */
    .info-section {
      margin-bottom: 15px;
    }

    .info-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 10pt;
    }

    .info-table td {
      padding: 2px 0;
      vertical-align: top;
    }

    .info-left {
      width: 50%;
    }

    .info-right {
      width: 50%;
      padding-left: 40px;
    }

    .label {
      display: inline-block;
      width: 90px;
    }

    /* Opening Text */
    .opening-text {
      margin: 15px 0;
      text-align: justify;
      line-height: 1.6;
    }

    /* Items Table */
    .items-table {
      width: 100%;
      border-collapse: collapse;
      margin: 10px 0;
    }

    .items-table th {
      border: 1px solid #000;
      padding: 6px 8px;
      text-align: center;
      font-weight: bold;
      font-size: 10pt;
      background-color: #fff;
    }

    .items-table td {
      border: 1px solid #000;
      padding: 6px 8px;
      font-size: 10pt;
      vertical-align: top;
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

    /* Summary in Table */
    .items-table .summary-row {
      font-weight: normal;
      background-color: #fff;
    }

    .items-table .summary-row td {
      border: 1px solid #000;
    }

    .items-table .total-row {
      font-weight: bold;
    }

    /* Closing Text */
    .closing-text {
      margin: 20px 0;
      text-align: justify;
      line-height: 1.6;
    }

    /* Signature */
    .signature-section {
      margin-top: 30px;
    }

    .signature-title {
      margin-bottom: 70px;
    }

    .signature-name {
      text-decoration: underline;
    }

    /* Payment Terms Box */
    .terms-box {
      margin: 15px 0;
      padding: 10px;
      border: 1px solid #000;
      background-color: #f9f9f9;
    }

    .terms-title {
      font-weight: bold;
      margin-bottom: 5px;
    }
  </style>
</head>

<body>
  <div class="container">
    <!-- Header / Kop Surat -->
    <div style="text-align:center; margin-bottom:15px;">
      @if ($company->logo_path)
        <img
          src="{{ public_path('storage/' . $company->logo_path) }}"
          style="width:800px; height:auto;"
          alt="Logo"
        >
      @endif
    </div>

    <!-- Title -->
    <div class="title-section">
      <div class="title">PURCHASE ORDER SUPPLIER</div>
    </div>

    <!-- Supplier and PO Info -->
    <div class="info-section">
      <table class="info-table">
        <tr>
          <td class="info-left">
            <span class="label">To</span>: {{ $supplier->supplier_name }}
          </td>
          <td class="info-right">
            Date Purchase : {{ \Carbon\Carbon::parse($po->po_date)->format('d F Y') }}
          </td>
        </tr>
        <tr>
          <td class="info-left">
            <span class="label"></span>&nbsp;&nbsp;{{ $supplier->address ?? '' }}
          </td>
          <td class="info-right">
            Number Purchase : {{ $po->po_number }}
          </td>
        </tr>
        <tr>
          <td class="info-left">
            <span class="label"></span>&nbsp;&nbsp;{{ $supplier->city ?? '' }}
          </td>
          <td class="info-right">
            @if ($po->expected_delivery_date)
              Expected Delivery : {{ \Carbon\Carbon::parse($po->expected_delivery_date)->format('d F Y') }}
            @endif
          </td>
        </tr>
        <tr>
          <td class="info-left">
            <span class="label">Up</span>: {{ $supplier->contact_person ?? '-' }}
          </td>
          <td class="info-right">
            Contact person : {{ $supplier->contact_person ?? '' }}
          </td>
        </tr>
        <tr>
          <td class="info-left">
            <span class="label">Tlp/Fax</span>: {{ $supplier->phone ?? '-' }}
          </td>
          <td class="info-right">
            Phone : {{ $supplier->phone ?? '' }}
          </td>
        </tr>
      </table>
    </div>

    <!-- Payment Terms -->
    @if ($po->terms)
      <div class="terms-box">
        <div class="terms-title">Payment Terms:</div>
        <div>{{ $po->terms }}</div>
      </div>
    @endif

    <!-- Opening Text -->
    <div class="opening-text">
      Dengan hormat,
    </div>

    <div class="opening-text">
      Melalui surat ini, kami bermaksud untuk melakukan pemesanan barang kepada {{ $supplier->supplier_name }} dengan spesifikasi sebagai berikut:
    </div>

    <!-- Items Table with Summary Inside -->
    <table class="items-table">
      <thead>
        <tr>
          <th style="width: 5%;">No</th>
          <th style="width: 30%;">Nama Barang</th>
          <th style="width: 15%;">Kode Produk</th>
          <th style="width: 10%;">Jumlah</th>
          <th style="width: 15%;">Harga Satuan</th>
          <th style="width: 8%;">Diskon</th>
          <th style="width: 17%;">Total Harga</th>
        </tr>
      </thead>
      <tbody>
        @foreach ($items as $index => $item)
          <tr>
            <td class="text-center">{{ $index + 1 }}</td>
            <td class="text-left">{{ $item->product_name }}</td>
            <td class="text-center">{{ $item->product_code ?? '-' }}</td>
            <td class="text-center">{{ $item->quantity }} {{ $item->unit }}</td>
            <td class="text-right">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
            <td class="text-center">{{ number_format($item->discount_percent, 1) }}%</td>
            <td class="text-right">Rp {{ number_format($item->total, 0, ',', '.') }}</td>
          </tr>
        @endforeach

        <!-- Summary Rows -->
        <tr class="summary-row">
          <td colspan="6" class="text-right"><strong>Subtotal</strong></td>
          <td class="text-right"><strong>Rp {{ number_format($subtotal, 0, ',', '.') }}</strong></td>
        </tr>
        @if ($tax > 0)
          <tr class="summary-row">
            <td colspan="6" class="text-right"><strong>PPN/Pajak</strong></td>
            <td class="text-right"><strong>Rp {{ number_format($tax, 0, ',', '.') }}</strong></td>
          </tr>
        @endif
        @if ($discount > 0)
          <tr class="summary-row">
            <td colspan="6" class="text-right"><strong>Diskon Total</strong></td>
            <td class="text-right"><strong>- Rp {{ number_format($discount, 0, ',', '.') }}</strong></td>
          </tr>
        @endif
        <tr class="total-row">
          <td colspan="6" class="text-right"><strong>Grand Total</strong></td>
          <td class="text-right"><strong>Rp {{ number_format($grand_total, 0, ',', '.') }}</strong></td>
        </tr>
      </tbody>
    </table>

    <!-- Notes -->
    @if ($po->notes)
      <div class="opening-text">
        <strong>Catatan:</strong><br>
        {{ $po->notes }}
      </div>
    @endif

    <!-- Closing Text -->
    <div class="closing-text">
      Demikian Purchase Order ini kami sampaikan. Mohon kiranya dapat diproses sesuai dengan waktu yang telah ditentukan. Atas perhatian dan kerjasamanya, kami ucapkan terima kasih.
    </div>

    <!-- Signature -->
    <div class="signature-section">
      <div class="signature-title">Best Regards,</div>
      <div style="margin-top: 80px;">
        @if ($po->signed_name)
          <div style="text-decoration: underline; font-weight: bold;">
            {{ $po->signed_name }}
          </div>
          <div style="margin-top: 5px;">
            {{ $po->signed_position }}
          </div>
          @if ($po->signed_city && $po->issued_at)
            <div style="margin-top: 10px; font-size: 9pt; color: #666;">
              {{ $po->signed_city }}, {{ \Carbon\Carbon::parse($po->issued_at)->format('d F Y') }}
            </div>
          @endif
        @else
          <div style="text-decoration: underline;">
            _______________________
          </div>
        @endif
      </div>
    </div>

  </div>
</body>

</html>
