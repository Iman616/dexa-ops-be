<!DOCTYPE html>
<html lang="id">

<head>
  <meta charset="UTF-8">
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
  >
  <title>Surat Jalan - {{ $deliveryNote->delivery_note_number }}</title>

  <style>
    @font-face {
      font-family: 'Calibri';
      src: url('{{ storage_path('fonts/Calibri.ttf') }}') format('truetype');
    }

    @font-face {
      font-family: 'Cambria';
      src: url('{{ storage_path('fonts/Cambria.ttf') }}') format('truetype');
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Calibri', Arial, sans-serif;
      padding: 40px 60px;
      font-size: 11pt;
      line-height: 1.3;
    }

    .header {
      margin-bottom: 30px;
    }

    th {
      font-family: 'Calibri', Arial, sans-serif;
    }

    td {
      font-family: 'Cambria', serif;
    }


    .company-name {
      font-size: 13pt;
      font-weight: bold;
    }

    .company-address {
      font-size: 9pt;
    }

    .title-section {
      text-align: center;
      font-size: 14pt;
      font-weight: bold;
      text-decoration: underline;
      margin: 35px 0 25px;
      letter-spacing: 1px;
    }

    .doc-info-row {
      display: table;
      width: 100%;
      margin-bottom: 4px;
      font-size: 10pt;
    }

    .doc-info-left,
    .doc-info-right {
      display: table-cell;
      width: 50%;
      vertical-align: top;
    }

    .doc-info-right {
      padding-left: 120px;
      /* atur: 20 / 30 / 40 sesuai selera */
    }

    .info-item {
      display: flex;
    }

    .info-label {
      min-width: 70px;
    }

    .info-colon {
      margin: 0 5px;
    }

    table {
      font-family: 'Cambria', serif;
      width: 100%;
      border-collapse: collapse;
      margin: 20px 0 30px;
      font-size: 10pt;
    }

    th,
    td {
      border: 1px solid #000;
      padding: 8px 6px;
      text-align: center;
    }

    td.text-left {
      text-align: left;
    }

    .signature-container {
      margin-top: 40px;
    }

    .signature-row {
      display: table;
      width: 100%;
    }

    .signed-date {
  font-size: 12px;
}

    .signature-col {
      display: table-cell;
      width: 33.33%;
      text-align: center;
      font-size: 10pt;
    }

    .signature-col.left {
      text-align: left;
    }

    .signature-col.right {
      text-align: right;
    }

    .signature-role {
      margin-bottom: 70px;
    }

    .signature-name.with-underline {
      text-decoration: underline;
    }
  </style>
</head>

<body>

  <!-- HEADER -->
  <div class="header">
    <div class="company-name">{{ $deliveryNote->company->company_name }}</div>
    <div class="company-address">{{ $deliveryNote->company->address }}</div>
    <div class="company-address">{{ $deliveryNote->company->city }}</div>
    <div class="company-address">Tel: {{ $deliveryNote->company->phone }}</div>
  </div>

  <div class="title-section">SURAT JALAN</div>

  <!-- INFO -->
  <div class="doc-info-row">
    <div class="doc-info-left">
      <div class="info-item">
        <span class="info-label">No</span><span class="info-colon">:</span>
        <span>{{ $deliveryNote->delivery_note_number }}</span>
      </div>
    </div>
    <div class="doc-info-right">
      <div class="info-item">
        <span class="info-label">Tanggal</span><span class="info-colon">:</span>
        <span>{{ $deliveryNote->delivery_date->format('d F Y') }}</span>
      </div>
    </div>
  </div>

  <div class="doc-info-row">
    <div class="doc-info-left">
      <div class="info-item">
        <span class="info-label">Instansi</span><span class="info-colon">:</span>
        <span>{{ $deliveryNote->recipient_name }}</span>
      </div>
    </div>
    <div class="doc-info-right">
      <div class="info-item">
        <span class="info-label">Alamat</span><span class="info-colon">:</span>
        <span>{{ $deliveryNote->recipient_address ?? '-' }}</span>
      </div>
    </div>
  </div>

  <!-- TABLE -->
  <table>
<thead>
<tr style="background-color:#f2f2f2;">
    <th>No</th>
    <th>Nama Barang</th>
    <th>Katalog</th>
    <th colspan="2">Jumlah</th>
    <th>Keterangan</th>
  </tr>
  <tr>
  </tr>
</thead>
    <tbody>
      @foreach ($deliveryNote->items as $i => $item)
        <tr>
          <td>{{ $i + 1 }}</td>
          <td class="text-left">{{ $item->product_name }}</td>
          <td>{{ $item->product_code ?? '-' }}</td>
<td>{{ number_format($item->quantity) }}</td>
<td>{{ $item->unit }}</td>

          <td>{{ $item->notes ?? '-' }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <!-- SIGNATURE -->
  <div class="signature-container">
<div class="signed-date">
  {{ $deliveryNote->signed_city }}, {{ optional($deliveryNote->signed_at)->format('d F Y') }}
</div>
    <div class="signature-row">
      <div class="signature-col left">
        <div class="signature-role"></div>
        <div class="signature-name with-underline">
          {{ $deliveryNote->signed_name }}
        </div>
        <div>{{ $deliveryNote->signed_position }}</div>
      </div>

      <div class="signature-col">
        <div class="signature-role">Pengirim</div>
        (..............................)
      </div>

      <div class="signature-col right">
        <div class="signature-role">Penerima</div>
        (..............................)
      </div>
    </div>
  </div>

</body>

</html>
