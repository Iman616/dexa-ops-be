<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Proforma Invoice - {{ $proforma->proforma_number }}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: Arial, sans-serif;
      font-size: 11px;
      color: #000;
      padding: 25px 30px;
    }

    /* =========================================================
       HEADER : Logo kiri + Info perusahaan kanan
    ========================================================= */
    .header-wrapper {
      display: table;
      width: 100%;
      margin-bottom: 6px;
    }
    .header-logo {
      display: table-cell;
      width: 160px;
      vertical-align: middle;
      padding-right: 15px;
    }
    .header-logo img {
      max-width: 150px;
      height: auto;
    }
    .header-divider {
      display: table-cell;
      width: 4px;
      background-color: #c00;   /* Garis merah vertikal seperti di PDF */
      vertical-align: top;
    }
    .header-company {
      display: table-cell;
      vertical-align: middle;
      padding-left: 15px;
    }
    .header-company .company-name {
      font-size: 22px;
      font-weight: bold;
      color: #003087;           /* Biru navy seperti di PDF */
      letter-spacing: 0.5px;
      margin-bottom: 4px;
    }
    .header-company .company-detail {
      font-size: 9.5px;
      line-height: 1.6;
      color: #333;
    }
    .header-npwp {
      text-align: right;
      font-size: 10px;
      font-weight: bold;
      margin-top: 4px;
      border-top: 2px solid #c00;
      padding-top: 3px;
    }

    /* =========================================================
       TITLE
    ========================================================= */
    .invoice-title {
      text-align: center;
      margin: 22px 0 18px;
    }
    .invoice-title h2 {
      font-size: 15px;
      font-weight: bold;
      letter-spacing: 6px;
      text-decoration: underline;
      text-underline-offset: 4px;
    }

    /* =========================================================
       INFO BARIS : Nomor/Tanggal | No. Surat Pesanan/Paket
    ========================================================= */
    .info-row {
      width: 100%;
      margin-bottom: 16px;
    }
    .info-row table { width: 100%; }
    .info-row td { vertical-align: top; padding: 1px 0; font-size: 11px; }
    .info-row .info-label { font-weight: bold; width: 70px; }
    .info-row .info-colon { width: 12px; }

    /* =========================================================
       CUSTOMER BOX
    ========================================================= */
    .customer-box {
      width: 100%;
      border: 1px solid #000;
      border-collapse: collapse;
      margin-bottom: 16px;
      font-size: 11px;
    }
    .customer-box td {
      padding: 6px 10px;
      vertical-align: top;
    }
    .customer-box .customer-left {
      width: 50%;
      border-right: 1px solid #000;
    }
    .customer-box .right-label {
      font-weight: bold;
      white-space: nowrap;
      padding-right: 6px;
    }
    .customer-box .right-colon { padding: 0 4px; }

    /* =========================================================
       ITEMS TABLE
    ========================================================= */
    .items-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 10px;
      margin-bottom: 0;
    }
    .items-table th, .items-table td {
      border: 1px solid #000;
      padding: 5px 6px;
    }
    .items-table thead tr th {
      background-color: #f2f2f2;
      text-align: center;
      font-weight: bold;
    }
    /* Baris header atas (QTY spanning 2 kolom) */
    .items-table .th-qty { text-align: center; }
    /* Baris header bawah (Vol | Satuan) */
    .items-table .th-sub { font-size: 9px; }

    .items-table td.center { text-align: center; }
    .items-table td.right  { text-align: right; white-space: nowrap; }

    /* =========================================================
       TOTAL SECTION (rata kanan)
    ========================================================= */
    .total-wrapper {
      width: 100%;
      border-collapse: collapse;
    }
    .total-wrapper .total-inner {
      width: 100%;
      border-collapse: collapse;
      border: 1px solid #000;
      border-top: none; /* menyambung dari tabel items */
    }
    .total-inner td {
      padding: 4px 8px;
      font-size: 10.5px;
      border: none;
    }
    .total-inner tr { border-top: 1px solid #aaa; }
    .total-inner .t-label { text-align: right; font-weight: bold; width: 82%; border-right: 1px solid #000; }
    .total-inner .t-value { text-align: right; white-space: nowrap; }
    .total-inner .t-grand .t-label,
    .total-inner .t-grand .t-value { font-weight: bold; }
    /* baris subtotal – label rata kanan menyambung dari kolom Jumlah */

    /* =========================================================
       TERBILANG
    ========================================================= */
    .terbilang-row td {
      border: 1px solid #000;
      padding: 5px 8px;
      font-size: 10px;
      font-style: italic;
      font-weight: bold;
    }

    /* =========================================================
       BANK INFO
    ========================================================= */
    .bank-section {
      margin-top: 18px;
      font-size: 10.5px;
      line-height: 1.8;
    }
    .bank-section strong { display: inline-block; }

    /* =========================================================
       SIGNATURE
    ========================================================= */
    .signature-section {
      margin-top: 36px;
      text-align: left;
      font-size: 11px;
    }
    .signature-section .sig-city { margin-bottom: 4px; }
    .signature-section .sig-space { height: 70px; }
    .signature-section .sig-name {
      font-weight: bold;
      text-decoration: underline;
      text-underline-offset: 3px;
    }
    .signature-section .sig-position { margin-top: 2px; }
  </style>
</head>
<body>

  {{-- =========================================================
       HEADER : Logo + Nama Perusahaan
  ========================================================= --}}
  <div class="header-wrapper">
    <div style="text-align:center; margin-bottom:25;">

@if($company->logo_base64)
  <img
      src="{{ $company->logo_base64 }}"
      style="width:750px; height:auto;"
      alt="Logo 
    >@endif
</div>

    {{-- Garis merah vertikal --}}
    <div class="header-divider"></div>

 
  </div>

  {{-- =========================================================
       TITLE
  ========================================================= --}}
  <div class="invoice-title">
    <h2>P R O F O R M A &nbsp;&nbsp; I N V O I C E</h2>
  </div>

  {{-- =========================================================
       NOMOR / TANGGAL  |  NO. SURAT PESANAN / PAKET PEKERJAAN
  ========================================================= --}}
  <div class="info-row">
    <table>
      <tr>
        <td style="width:50%; vertical-align:top;">
          <table>
            <tr>
              <td class="info-label">Nomor</td>
              <td class="info-colon">:</td>
              <td><strong>{{ $proforma->proforma_number }}</strong></td>
            </tr>
            <tr>
              <td class="info-label">Tanggal</td>
              <td class="info-colon">:</td>
              <td><strong>{{ \Carbon\Carbon::parse($proforma->proforma_date)->translatedFormat('d F Y') }}</strong></td>
            </tr>
          </table>
        </td>
       
      </tr>
    </table>
  </div>

  {{-- =========================================================
       CUSTOMER BOX
  ========================================================= --}}
  <table class="customer-box">
    <tr>
      {{-- Kolom kiri: nama & alamat customer --}}
      <td class="customer-left">
        <div style="font-weight:bold; margin-bottom:3px;">Kepada Yth:</div>
        <div style="font-weight:bold;">{{ strtoupper($proforma->customer_name) }}</div>
        @if($proforma->customer_address)
          <div>{{ $proforma->customer_address }}</div>
        @endif
      </td>

      {{-- Kolom kanan: no surat pesanan & paket --}}
      <td>
        <table style="width:100%;">
          @if(!empty($proforma->po_number))
          <tr>
            <td class="right-label">No. Surat Pesanan</td>
            <td class="right-colon">:</td>
            <td>{{ $proforma->po_number }}</td>
          </tr>
          @endif
          @if(!empty($proforma->project_name))
          <tr>
            <td class="right-label">Paket Pekerjaan</td>
            <td class="right-colon">:</td>
            <td>{{ $proforma->project_name }}</td>
          </tr>
          @endif
        </table>
      </td>
    </tr>
  </table>

  {{-- =========================================================
       ITEMS TABLE
       Header: No | Jenis Barang | Spesifikasi | QTY(Vol/Satuan) | Satuan(Rp) | Jumlah(Rp)
  ========================================================= --}}
  <table class="items-table">
    <thead>
      {{-- Baris 1: header utama --}}
      <tr>
        <th rowspan="2" style="width:28px;">No</th>
        <th rowspan="2" style="width:150px;">Jenis Barang</th>
        <th rowspan="2">Spesifikasi</th>
        <th colspan="2" class="th-qty">QTY</th>
        <th rowspan="2" style="width:88px;">
          Satuan<br><span style="font-size:9px;">(Rp)</span>
        </th>
        <th rowspan="2" style="width:100px;">
          Jumlah<br><span style="font-size:9px;">(Rp)</span>
        </th>
      </tr>
      {{-- Baris 2: sub-header QTY --}}
      <tr>
        <th class="th-sub" style="width:38px;">Vol</th>
        <th class="th-sub" style="width:52px;">Satuan</th>
      </tr>
    </thead>
    <tbody>
      @foreach($items as $index => $item)
      <tr>
        <td class="center">{{ $index + 1 }}</td>
        <td>{{ $item->product_name }}</td>
        <td class="center">{{ $item->product_description ?? $item->product_code }}</td>
        <td class="center">{{ number_format($item->quantity, 0) }}</td>
        <td class="center">{{ $item->unit }}</td>
        <td class="right">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
        <td class="right">Rp {{ number_format($item->quantity * $item->unit_price, 0, ',', '.') }}</td>
      </tr>
      @endforeach

      {{-- Baris Subtotal --}}
      <tr>
        <td colspan="6" style="text-align:right; font-weight:bold; border-right:1px solid #000;">
          Subtotal
        </td>
        <td class="right" style="font-weight:bold;">
          Rp {{ number_format($subtotal, 0, ',', '.') }}
        </td>
      </tr>

      {{-- Baris PPN --}}
      <tr>
        <td colspan="6" style="text-align:right; font-weight:bold; border-right:1px solid #000;">
          PPN {{ number_format($proforma->tax_percentage, 0) }}%
        </td>
        <td class="right">
          Rp {{ number_format($ppn, 0, ',', '.') }}
        </td>
      </tr>

      {{-- Baris Diskon (opsional) --}}
      @if(!empty($proforma->discount_amount) && $proforma->discount_amount > 0)
      <tr>
        <td colspan="6" style="text-align:right; font-weight:bold; border-right:1px solid #000;">
          Diskon
        </td>
        <td class="right">
          - Rp {{ number_format($proforma->discount_amount, 0, ',', '.') }}
        </td>
      </tr>
      @endif

      {{-- Baris Total --}}
      <tr>
        <td colspan="6" style="text-align:right; font-weight:bold; border-right:1px solid #000;">
          Total
        </td>
        <td class="right" style="font-weight:bold;">
          Rp {{ number_format($total, 0, ',', '.') }}
        </td>
      </tr>

      {{-- Baris Terbilang --}}
      <tr class="terbilang-row">
        <td colspan="7">
          <strong>Terbilang :</strong>&nbsp;&nbsp;{{ $terbilang ?? '' }}
        </td>
      </tr>

    </tbody>
  </table>

  {{-- =========================================================
       BANK INFO
  ========================================================= --}}
  <div class="bank-section">
    <p>Please remit the above amount to. <strong>An. {{ strtoupper($company->company_name) }}</strong></p>
    <p>account with :</p>
    @if($company->bank_name)
      <p>Bank &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: {{ $company->bank_name }}</p>
    @endif
    @if($company->bank_account)
      <p>Account : {{ $company->bank_account }} (IDR)</p>
    @endif
  </div>

  {{-- =========================================================
       SIGNATURE
  ========================================================= --}}
  <div class="signature-section">
    <p class="sig-city">
      {{ $company->city ?? $proforma->signed_city ?? 'Bogor' }},
      {{ \Carbon\Carbon::parse($proforma->proforma_date)->translatedFormat('d F Y') }}
    </p>

    <div class="sig-space">
      @if($proforma->signature_image_base64)
        <img src="{{ $proforma->signature_image_base64 }}" height="65" style="margin-top:4px;">
      @endif
    </div>

    <p class="sig-name">{{ $proforma->signed_name ?? $proforma->creator_name ?? $company->pic_name }}</p>

    @if($proforma->signed_position)
      <p class="sig-position">{{ $proforma->signed_position }}</p>
    @endif
  </div>

</body>
</html>