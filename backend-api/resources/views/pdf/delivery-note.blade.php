<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'Calibri', Arial, sans-serif;
      font-size: 10.5pt;
      color: #000;
      padding: 28px 40px;
      line-height: 1.35;
    }

    /* =========================================================
       HEADER
    ========================================================= */
    .header-table {
      width: 100%;
      border-bottom: 3px solid #c00;
      padding-bottom: 6px;
      margin-bottom: 0;
      display: table;
    }
    .header-logo-cell {
      text-align: center;
      margin-bottom: 20px;
      padding-bottom: 10px;
    }
    .header-logo-cell img {
      max-width: 100%;
      height: auto;
    }
    .header-info-cell {
      display: table-cell;
      vertical-align: middle;
    }
    .header-company-name {
      font-size: 26pt;
      font-weight: bold;
      color: #1a3a6e;          /* Biru navy seperti di PDF */
      font-family: 'Calibri', Arial, sans-serif;
      letter-spacing: 0.5px;
      line-height: 1.1;
    }
    .header-tagline {
      font-size: 11pt;
      font-weight: bold;
      color: #444;
      margin-bottom: 3px;
    }
    .header-detail {
      font-size: 8.5pt;
      color: #333;
      line-height: 1.55;
    }
    .header-npwp {
      display: table-cell;
      vertical-align: bottom;
      text-align: right;
      font-size: 8.5pt;
      white-space: nowrap;
      padding-bottom: 4px;
    }

    /* =========================================================
       TITLE
    ========================================================= */
    .title-section {
      text-align: center;
      font-size: 13pt;
      font-weight: bold;
      text-decoration: underline;
      text-underline-offset: 4px;
      margin: 20px 0 16px;
      letter-spacing: 1.5px;
      font-family: 'Calibri', Arial, sans-serif;
    }

    /* =========================================================
       NOMOR & TANGGAL (dua baris, kiri)
    ========================================================= */
    .doc-info {
      margin-bottom: 14px;
      font-size: 10pt;
    }
    .doc-info-item {
      display: flex;
      align-items: baseline;
      margin-bottom: 2px;
    }
    .doc-info-label {
      font-weight: bold;
      min-width: 68px;
    }
    .doc-info-colon {
      margin: 0 6px;
      font-weight: bold;
    }

    /* =========================================================
       CUSTOMER BOX (berborder seperti proforma)
    ========================================================= */
    .customer-box {
      width: 100%;
      border: 1px solid #000;
      border-collapse: collapse;
      margin-bottom: 16px;
      font-size: 10pt;
    }
    .customer-box td {
      padding: 6px 10px;
      vertical-align: top;
    }
    .customer-box .col-left {
      width: 50%;
      border-right: 1px solid #000;
    }
    .customer-box .col-right {
      width: 50%;
    }
    .cbox-label {
      font-weight: bold;
      white-space: nowrap;
    }
    .cbox-inner {
      width: 100%;
      border-collapse: collapse;
    }
    .cbox-inner td {
      padding: 1px 0;
      border: none;
    }
    .cbox-inner .ci-label {
      font-weight: bold;
      white-space: nowrap;
      padding-right: 6px;
      width: auto;
    }
    .cbox-inner .ci-colon {
      padding: 0 4px;
    }

    /* =========================================================
       TABEL BARANG
    ========================================================= */
    .items-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 9.5pt;
      margin-bottom: 16px;
      font-family: 'Cambria', serif;
    }
    .items-table th {
      border: 1px solid #000;
      padding: 6px 5px;
      background-color: #f2f2f2;
      text-align: center;
      font-family: 'Calibri', Arial, sans-serif;
      font-size: 9.5pt;
    }
    .items-table td {
      border: 1px solid #000;
      padding: 5px 5px;
      text-align: center;
      font-family: 'Cambria', serif;
    }
    .items-table td.text-left {
      text-align: left;
    }
    .items-table td.text-right {
      text-align: right;
    }

    /* =========================================================
       SYARAT DAN KETENTUAN
    ========================================================= */
    .terms-section {
      font-size: 9pt;
      margin-bottom: 22px;
      line-height: 1.55;
    }
    .terms-section .terms-title {
      font-weight: bold;
      text-decoration: underline;
      margin-bottom: 5px;
    }
    .terms-section ol {
      padding-left: 16px;
    }
    .terms-section ol li {
      margin-bottom: 3px;
      text-align: justify;
    }

    /* =========================================================
       TANDA TANGAN
    ========================================================= */
    .signature-section {
      margin-top: 10px;
    }
    .signed-date {
      font-size: 10pt;
      margin-bottom: 6px;
    }
    .sig-row {
      display: table;
      width: 100%;
    }
    .sig-col {
      display: table-cell;
      width: 33.33%;
      vertical-align: top;
      font-size: 10pt;
    }
    .sig-col.center {
      text-align: center;
    }
    .sig-col.right {
      text-align: right;
    }
    .sig-role {
      font-weight: normal;
      margin-bottom: 65px;   /* ruang tanda tangan */
    }
    .sig-blank {
      font-size: 10pt;
    }
    .sig-name {
      font-weight: bold;
      text-decoration: underline;
      text-underline-offset: 2px;
    }
    .sig-position {
      font-size: 10pt;
    }
    .sig-stamp {
      height: 70px;
      display: block;
      margin-bottom: 0;
    }
    .sig-stamp img {
      height: 70px;
      width: auto;
    }
  </style>
</head>
<body>

  {{-- =========================================================
       HEADER : Logo + Nama Perusahaan + NPWP
  ========================================================= --}}
  <div class="header-table">

    {{-- Logo --}}
   <div class="header-logo-cell">
    @if($deliveryNote->company->logo_base64)
      <img src="{{ $deliveryNote->company->logo_base64 }}" alt="Logo {{ $deliveryNote->company->company_name }}">
    @endif
  </div>

    {{-- Info perusahaan --}}
    <div class="header-info-cell">
    </div>


  </div>{{-- end .header-table --}}

  {{-- =========================================================
       TITLE
  ========================================================= --}}
  <div class="title-section">SURAT JALAN</div>

  {{-- =========================================================
       NOMOR & TANGGAL (kiri, baris terpisah)
  ========================================================= --}}
  <div class="doc-info">
    <div class="doc-info-item">
      <span class="doc-info-label">Nomor</span>
      <span class="doc-info-colon">:</span>
      <span><strong>{{ $deliveryNote->delivery_note_number }}</strong></span>
    </div>
    <div class="doc-info-item">
      <span class="doc-info-label">Tanggal</span>
      <span class="doc-info-colon">:</span>
      <span><strong>{{ $deliveryNote->delivery_date->translatedFormat('d F Y') }}</strong></span>
    </div>
  </div>

  {{-- =========================================================
       CUSTOMER BOX : Kepada Yth | No. SP / Paket Pekerjaan
  ========================================================= --}}
  <table class="customer-box">
    <tr>
      {{-- Kolom kiri: penerima --}}
      <td class="col-left">
        <div class="cbox-label">Kepada Yth :</div>
        <div>{{ $deliveryNote->recipient_name }}</div>
      </td>

      {{-- Kolom kanan: nomor SP & paket --}}
      <td class="col-right">
        <table class="cbox-inner">
          <tr>
            <td class="ci-label">No. Surat Perintah Kerja / SP</td>
            <td class="ci-colon">:</td>
            <td>{{ $deliveryNote->po_number ?? '-' }}</td>
          </tr>
          <tr>
            <td class="ci-label">Paket Pekerjaan</td>
            <td class="ci-colon">:</td>
            <td>{{ $deliveryNote->project_name ?? '-' }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- =========================================================
       TABEL BARANG
       Header: No | Nama Barang | Spesifikasi | Jumlah (qty + unit) | Keterangan
  ========================================================= --}}
  <table class="items-table">
    <thead>
      <tr style="background-color:#f2f2f2;">
   <th style="width:4%;">No</th>
<th style="width:25%;">Nama Barang</th>
<th style="width:12%;">Brand / Merk</th>
<th style="width:14%;">Katalog/Kode</th>
<th colspan="2" style="width:12%;">Jumlah</th>
<th style="width:10%;">Keterangan</th>
      </tr>
    </thead>
    <tbody>
      @foreach($deliveryNote->items as $i => $item)
      <tr>
        <td>{{ $i + 1 }}</td>
<td class="text-left">{{ $item->product_name }}</td>
<td>{{ $item->product?->brand ?? '-' }}</td>
<td>
  {{ $item->product_code ?? $item->product?->product_code ?? '-' }}
  @if($item->product?->description)
    <br><small style="color:#555; font-size:8pt;">{{ $item->product->description }}</small>
  @endif
</td>        <td style="width:42px;">{{ number_format($item->quantity) }}</td>
        <td style="width:48px;">{{ $item->unit }}</td>
        <td>{{ $item->notes ?? '' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  {{-- =========================================================
       SYARAT DAN KETENTUAN
  ========================================================= --}}
  <div class="terms-section">
    <div class="terms-title">Syarat dan Ketentuan :</div>
    @if(!empty($deliveryNote->terms))
      {{-- Jika tersedia dari DB --}}
      {!! $deliveryNote->terms !!}
    @else
      {{-- Default terms --}}
      <ol>
        <li>Apabila barang yang dikirimkan sudah sesuai dengan Surat Pesanan atau Surat Perintah Kerja (SPK) maka tidak dapat dikembalikan dengan alasan apapun.</li>
        <li>Retur dapat dilakukan apabila barang yang dikirim tidak sesuai dengan Surat Pesanan atau Surat Perintah Kerja (SPK).</li>
        <li>Jangka waktu retur adalah sebelum Berita Acara Serah Terima ditandatangani kedua belah pihak, apabila pemesanan dilakukan tanpa BAST maka jangka waktu retur adalah maksimal 3 hari sejak Surat Jalan ditandatangani.</li>
      </ol>
    @endif
  </div>

  {{-- =========================================================
       TANDA TANGAN (3 kolom: TTD resmi | Pengirim | Penerima)
  ========================================================= --}}
  <div class="signature-section">

    {{-- Tanggal tanda tangan (kiri bawah, di atas blok TTD) --}}
    <div class="signed-date">
      {{ $deliveryNote->signed_city }},
      {{ optional($deliveryNote->signed_at)->translatedFormat('d F Y') }}
    </div>

    <div class="sig-row">

      {{-- Kolom kiri: TTD pejabat / perusahaan --}}
      <div class="sig-col">
        <div class="sig-stamp">
          @if($deliveryNote->signature_image_base64)
            <img src="{{ $deliveryNote->signature_image_base64 }}"
                 alt="Tanda Tangan">
          @endif
        </div>
        <div class="sig-name">{{ $deliveryNote->signed_name }}</div>
        <div class="sig-position">{{ $deliveryNote->signed_position }}</div>
      </div>

      {{-- Kolom tengah: Pengirim --}}
      <div class="sig-col center">
        <div class="sig-role">Pengirim</div>
        <div class="sig-blank">( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )</div>
      </div>

      {{-- Kolom kanan: Penerima --}}
      <div class="sig-col right">
        <div class="sig-role">Penerima,</div>
        <div class="sig-blank">( &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; )</div>
      </div>

    </div>{{-- end .sig-row --}}
  </div>

</body>
</html>
