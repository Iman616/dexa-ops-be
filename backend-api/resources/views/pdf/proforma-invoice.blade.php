<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Proforma Invoice - {{ $proforma->proforma_number }}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'Times New Roman', Times, serif;
      font-size: 11pt;
      line-height: 1.4;
      padding: 20px 40px;
      color: #000;
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
      background-color: #c00;
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
      color: #003087;
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
       INFO BARIS
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
      font-size: 8.5pt;
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
      vertical-align: middle;
      line-height: 1.2;
    }
    .items-table .th-qty { text-align: center; }
    .items-table .th-sub { font-size: 9px; }

    .items-table td.center { text-align: center; }
    .items-table td.right  { text-align: right; white-space: nowrap; }

    /* =========================================================
       SUMMARY
    ========================================================= */
    .summary-row  { text-align: right; font-weight: bold; padding-right: 8px; }
    .summary-value { text-align: right; font-weight: bold; padding-right: 8px; }
    .total-row td { font-weight: bold; background-color: #f9f9f9; }

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

    /* ✅ TERMS SECTION - sama persis dengan Quotation & PO */
    .terms-section { margin-top: 20px; page-break-inside: avoid; }
    .terms-section p { font-weight: bold; margin-bottom: 8px; font-size: 10pt; text-decoration: underline; }
    .terms-section ol { margin-left: 20px; font-size: 9.5pt; }
    .terms-section li { margin-bottom: 5px; text-align: justify; line-height: 1.3; }

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

    @page { margin: 1.5cm 2cm; }
  </style>
</head>
<body>

  {{-- =========================================================
       HEADER : Logo
  ========================================================= --}}
  <div style="text-align:center; margin-bottom:25px;">
    @if($company->logo_base64)
    <img
      src="{{ $company->logo_base64 }}"
      style="width:750px; height:auto;"
      alt="Logo"
    >
    @endif
  </div>

  {{-- =========================================================
       TITLE
  ========================================================= --}}
  <div class="invoice-title">
    <h2>P R O F O R M A &nbsp;&nbsp; I N V O I C E</h2>
  </div>

  {{-- =========================================================
       NOMOR / TANGGAL
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
      <td class="customer-left">
        <div style="font-weight:bold; margin-bottom:3px;">Kepada Yth:</div>
        <div style="font-weight:bold;">{{ strtoupper($proforma->customer_name) }}</div>
        @if($proforma->customer_address)
          <div>{{ $proforma->customer_address }}</div>
        @endif
      </td>

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
  ========================================================= --}}
  {{-- =========================================================
     ITEMS TABLE
========================================================= --}}
{{-- ITEMS TABLE --}}
<table class="items-table">
  <thead>
    <tr>
      <th rowspan="2" style="width:28px;">No</th>
      <th rowspan="2" style="width:150px;">Jenis Barang</th>
      <th rowspan="2" style="width:90px;">Brand</th>
      <th rowspan="2">Spesifikasi</th>
      <th colspan="2" class="th-qty">QTY</th>
      <th rowspan="2" style="width:88px;">
        Satuan<br><span style="font-size:9px;">(Rp)</span>
      </th>
      <th rowspan="2" style="width:100px;">
        Jumlah<br><span style="font-size:9px;">(Rp)</span>
      </th>
    </tr>
    <tr>
      <th class="th-sub" style="width:38px;">Vol</th>
      <th class="th-sub" style="width:52px;">Satuan</th>
    </tr>
  </thead>
  <tbody>
    @foreach($items as $index => $item)
    @php
      $itemGross  = $item->quantity * $item->unit_price;
      $discPct    = (float)($item->discount_percent ?? 0);
      $discAmount = $itemGross * ($discPct / 100);
      $itemNet    = $itemGross - $discAmount;
    @endphp
    <tr>
      <td class="center">{{ $index + 1 }}</td>
      <td>{{ $item->product_name }}</td>
      <td class="center">{{ $item->brand ?? '-' }}</td>
      <td class="center">{{ $item->product_description ?? $item->product_code ?? '-' }}</td>
      <td class="center">{{ number_format($item->quantity, 0) }}</td>
      <td class="center">{{ $item->unit }}</td>
      <td class="right">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
      {{-- Jumlah sudah net setelah diskon per item --}}
      <td class="right">Rp {{ number_format($itemNet, 0, ',', '.') }}</td>
    </tr>
    @endforeach

    {{-- Summary — colspan selalu 7 karena kolom diskon dihapus dari tabel --}}
    <tr>
      <td colspan="7" style="text-align:right; font-weight:bold; border-right:1px solid #000;">Subtotal</td>
      <td class="right" style="font-weight:bold;">Rp {{ number_format($subtotal, 0, ',', '.') }}</td>
    </tr>

    {{-- ✅ Baris diskon — hanya muncul jika ada nilai diskon --}}
    @if(!empty($proforma->discount_amount) && (float)$proforma->discount_amount > 0)
    <tr>
      <td colspan="7" style="text-align:right; font-weight:bold; border-right:1px solid #000;">
        Diskon
        @if(!empty($proforma->discount_percentage) && (float)$proforma->discount_percentage > 0)
          ({{ number_format((float)$proforma->discount_percentage, 0) }}%)
        @endif
      </td>
      <td class="right">- Rp {{ number_format($proforma->discount_amount, 0, ',', '.') }}</td>
    </tr>
    @endif

    <tr>
      <td colspan="7" style="text-align:right; font-weight:bold; border-right:1px solid #000;">
        PPN
      </td>
      <td class="right">Rp {{ number_format($ppn, 0, ',', '.') }}</td>
    </tr>

    <tr class="total-row">
      <td colspan="7" style="text-align:right; font-weight:bold; border-right:1px solid #000;">Total</td>
      <td class="right" style="font-weight:bold;">Rp {{ number_format($total, 0, ',', '.') }}</td>
    </tr>

    <tr class="terbilang-row">
      <td colspan="8"><strong>Terbilang :</strong>&nbsp;&nbsp;{{ $terbilang ?? '' }}</td>
    </tr>
  </tbody>
</table>

  {{-- ✅ Dynamic Terms Section --}}
  @if($terms && $terms->count() > 0)
  <div class="terms-section">
    <p>Syarat dan Ketentuan :</p>
    <ol>
      @foreach($terms as $term)
      <li>{{ $term->term_content }}</li>
      @endforeach
    </ol>
  </div>
  @endif

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
