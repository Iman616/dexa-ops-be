<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >
  <title>Proforma Invoice - {{ $proforma->proforma_number }}</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: Arial, sans-serif;
      font-size: 11px;
      color: #000;
      padding: 20px;
    }

    .header {
      margin-bottom: 30px;
    }

    .header h1 {
      font-size: 18px;
      font-weight: bold;
      text-align: center;
      margin-bottom: 20px;
    }

    .invoice-info {
      margin-bottom: 15px;
    }

    .invoice-info table {
      width: 100%;
      margin-bottom: 10px;
    }

    .invoice-info td {
      padding: 3px 0;
    }

    .invoice-info .label {
      width: 100px;
      font-weight: normal;
    }

    .invoice-info .colon {
      width: 20px;
    }

    .customer-section {
      margin-bottom: 20px;
    }

    .customer-section p {
      margin: 3px 0;
    }

    .items-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 20px;
    }

    .items-table th {
      border: 1px solid #000;
      padding: 8px;
      background-color: #f0f0f0;
      font-weight: bold;
      text-align: center;
      font-size: 10px;
    }

    .items-table td {
      border: 1px solid #000;
      padding: 6px;
      font-size: 10px;
    }

    .items-table td.center {
      text-align: center;
    }

    .items-table td.right {
      text-align: right;
    }

    .totals-section {
      width: 100%;
      margin-bottom: 30px;
    }

    .totals-table {
      width: 350px;
      margin-left: auto;
      border-collapse: collapse;
    }

    .totals-table td {
      padding: 5px 10px;
      font-size: 11px;
    }

    .totals-table .label {
      text-align: left;
      width: 200px;
    }

    .totals-table .amount {
      text-align: right;
      width: 150px;
    }

    .totals-table .total-row {
      font-weight: bold;
      border-top: 1px solid #000;
    }

    .bank-info {
      margin-bottom: 20px;
      padding: 10px;
      background-color: #f9f9f9;
      border: 1px solid #ddd;
    }

    .bank-info p {
      margin: 3px 0;
      font-size: 10px;
    }

    .bank-info .bank-label {
      font-weight: bold;
      display: inline-block;
      width: 80px;
    }

    .signature-section {
      margin-top: 40px;
    }

    .signature-box {
      text-align: right;
    }

    .signature-box p {
      margin: 3px 0;
    }

    .signature-space {
      height: 60px;
    }

    .signature-name {
      font-weight: bold;
      text-decoration: underline;
    }

    .notes-section {
      margin-top: 20px;
      font-size: 10px;
      font-style: italic;
    }

    .format-currency {
      white-space: nowrap;
    }
  </style>
</head>

<body>
  <!-- Header -->
  <div class="header">
    <h1>PROFORMA INVOICE</h1>

    <div class="invoice-info">
      <table>
        <tr>
          <td class="label">Nomor</td>
          <td class="colon">:</td>
          <td><strong>{{ $proforma->proforma_number }}</strong></td>
        </tr>
        <tr>
          <td class="label">Tanggal</td>
          <td class="colon">:</td>
          <td>{{ \Carbon\Carbon::parse($proforma->proforma_date)->format('d F Y') }}</td>
        </tr>
      </table>
    </div>

    <div class="customer-section">
      <p><strong>Kepada Yth:</strong></p>
      <p><strong>{{ strtoupper($proforma->customer_name) }}</strong></p>

      @if ($proforma->customer_address)
        <p>{{ $proforma->customer_address }}</p>
      @endif
      @if ($proforma->customer_address)
        <p>{{ $proforma->customer_address }}</p>
      @endif
    </div>
  </div>

  <!-- Items Table -->
  <table class="items-table">
    <thead>
      <tr>
        <th style="width: 30px;">No</th>
        <th style="width: 150px;">Jenis Barang</th>
        <th>Spesifikasi</th>
        <th style="width: 50px;">QTY</th>
        <th style="width: 60px;">Satuan</th>
        <th style="width: 80px;">Vol</th>
        <th style="width: 60px;">Satuan</th>
        <th style="width: 90px;">Jumlah<br />(Rp)</th>
      </tr>
    </thead>
    <tbody>
      @foreach ($items as $index => $item)
        <tr>
          <td class="center">{{ $index + 1 }}</td>
          <td>{{ $item->product_name }}</td>
          <td>{{ $item->product_description ?? $item->product_code }}</td>
          <td class="center">{{ number_format($item->quantity, 0) }}</td>
          <td class="center">{{ $item->unit }}</td>
          <td class="right format-currency">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
          <td class="center">{{ $item->unit }}</td>
          <td class="right format-currency">Rp {{ number_format($item->quantity * $item->unit_price, 0, ',', '.') }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <!-- Totals Section -->
  <div class="totals-section">
    <table class="totals-table">
      <tr>
        <td class="label">Subtotal</td>
        <td class="amount format-currency">Rp {{ number_format($subtotal, 0, ',', '.') }}</td>
      </tr>
      <tr>
        <td class="label">DPP Lainnya (Konversi 11/12)</td>
        <td class="amount format-currency">Rp {{ number_format($dpp_lainnya, 0, ',', '.') }}</td>
      </tr>
      <tr>
        <td class="label">PPN {{ number_format($proforma->tax_percentage, 0) }}%</td>
        <td class="amount format-currency">Rp {{ number_format($ppn, 0, ',', '.') }}</td>
      </tr>
      @if ($proforma->discount_amount > 0)
        <tr>
          <td class="label">Diskon</td>
          <td class="amount format-currency">- Rp {{ number_format($proforma->discount_amount, 0, ',', '.') }}</td>
        </tr>
      @endif
      <tr class="total-row">
        <td class="label"><strong>Total</strong></td>
        <td class="amount format-currency"><strong>Rp {{ number_format($total, 0, ',', '.') }}</strong></td>
      </tr>
    </table>
  </div>

  <!-- Bank Information -->
  <div class="bank-info">
    <p><strong>Please remit the above amount to:</strong></p>
    <p><span class="bank-label">An.</span> {{ strtoupper($company->company_name) }}</p>
    <p><strong>account with:</strong></p>
    @if ($company->bank_name)
      <p><span class="bank-label">Bank</span>: {{ $company->bank_name }}</p>
    @endif
    @if ($company->bank_account)
      <p><span class="bank-label">Account</span>: {{ $company->bank_account }} (IDR)</p>
    @endif
  </div>

  <!-- Signature Section -->
  <div class="signature-section">
    <div class="signature-box">
      <p>{{ $company->city ?? 'Bogor' }}, {{ \Carbon\Carbon::parse($proforma->proforma_date)->format('d F Y') }}</p>
      <div class="signature-space"></div>
      <p class="signature-name">{{ $proforma->creator_name ?? $company->pic_name }}</p>
    </div>
  </div>

  <!-- Notes -->
  @if ($proforma->notes)
    <div class="notes-section">
      <p><strong>Notes:</strong></p>
      <p>{{ $proforma->notes }}</p>
    </div>
  @endif

  @if ($proforma->payment_terms)
    <div class="notes-section">
      <p><strong>Payment Terms:</strong> {{ $proforma->payment_terms }}</p>
    </div>
  @endif

  @if ($proforma->delivery_terms)
    <div class="notes-section">
      <p><strong>Delivery Terms:</strong> {{ $proforma->delivery_terms }}</p>
    </div>
  @endif
</body>

</html>
