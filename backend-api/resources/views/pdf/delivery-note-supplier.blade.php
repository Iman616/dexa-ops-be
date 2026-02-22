<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Surat Jalan - {{ $deliveryNote->delivery_note_number }}</title>

  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: Arial, sans-serif;
      font-size: 10.5pt;
      color: #000;
      padding: 28px 40px;
      line-height: 1.35;
    }

    .header-table {
      width: 100%;
      border-bottom: 3px solid #c00;
      padding-bottom: 6px;
      margin-bottom: 0;
      display: table;
    }
    .header-logo-cell {
      display: table-cell;
      width: 110px;
      vertical-align: middle;
      padding-right: 12px;
    }
    .header-logo-cell img {
      max-width: 100px;
      height: auto;
    }
    .header-info-cell {
      display: table-cell;
      vertical-align: middle;
    }
    .header-company-name {
      font-size: 22pt;
      font-weight: bold;
      color: #1a3a6e;
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

    .title-section {
      text-align: center;
      font-size: 13pt;
      font-weight: bold;
      text-decoration: underline;
      margin: 20px 0 16px;
      letter-spacing: 1.5px;
    }

    .doc-info {
      margin-bottom: 14px;
      font-size: 10pt;
    }
    .doc-info table td {
      padding: 1px 0;
      vertical-align: top;
    }
    .doc-info .di-label {
      font-weight: bold;
      width: 70px;
    }
    .doc-info .di-colon {
      width: 14px;
    }

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
    .cbox-label { font-weight: bold; }
    .cbox-inner { width: 100%; border-collapse: collapse; }
    .cbox-inner td { padding: 2px 0; border: none; }
    .cbox-inner .ci-label {
      font-weight: bold;
      white-space: nowrap;
      padding-right: 6px;
      width: 1%;
    }
    .cbox-inner .ci-colon { padding: 0 4px; width: 10px; }

    .items-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 9.5pt;
      margin-bottom: 16px;
    }
    .items-table th {
      border: 1px solid #000;
      padding: 6px 5px;
      background-color: #f2f2f2;
      text-align: center;
      font-size: 9.5pt;
    }
    .items-table td {
      border: 1px solid #000;
      padding: 5px;
      text-align: center;
    }
    .items-table td.text-left  { text-align: left; }
    .items-table td.text-right { text-align: right; }

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

    .signature-section { margin-top: 10px; }
    .signed-date { font-size: 10pt; margin-bottom: 6px; }

    .sig-row { display: table; width: 100%; }
    .sig-col {
      display: table-cell;
      width: 33.33%;
      vertical-align: top;
      font-size: 10pt;
    }
    .sig-col.center { text-align: center; }
    .sig-col.right  { text-align: right; }
    .sig-role    { font-weight: normal; margin-bottom: 65px; }
    .sig-blank   { font-size: 10pt; }
    .sig-name    { font-weight: bold; text-decoration: underline; }
    .sig-position { font-size: 10pt; }
    .sig-stamp   { height: 70px; display: block; }
    .sig-stamp img { height: 70px; width: auto; }
  </style>
</head>
<body>

  {{-- ===================== HEADER ===================== --}}
  <div class="header-table">

    {{-- Logo --}}
    <div class="header-logo-cell">
      @if(!empty($company->logo_base64))
        <img src="{{ $company->logo_base64 }}"
             alt="Logo {{ $company->company_name }}">
      @endif
    </div>

    {{-- Info perusahaan --}}
    <div class="header-info-cell">
      <div class="header-company-name">{{ $company->company_name }}</div>

      @if(!empty($company->tagline))
        <div class="header-tagline">{{ $company->tagline }}</div>
      @endif

      <div class="header-detail">
        {{ $company->address }}<br>
        @if(!empty($company->phone))
          Telp/Fax : {{ $company->phone }}
          @if(!empty($company->whatsapp))
            &nbsp; WA : {{ $company->whatsapp }}
          @endif
          <br>
        @endif
        @if(!empty($company->email))
          Email : {{ $company->email }}<br>
        @endif
        @if(!empty($company->website))
          Website : {{ $company->website }}
        @endif
      </div>
    </div>

    {{-- NPWP --}}
    @if(!empty($company->npwp))
    <div class="header-npwp">
      NPWP : {{ $company->npwp }}
    </div>
    @endif

  </div>

  {{-- ===================== TITLE ===================== --}}
  <div class="title-section">SURAT JALAN</div>

  {{-- ===================== NOMOR & TANGGAL ===================== --}}
  <div class="doc-info">
    <table>
      <tr>
        <td class="di-label">Nomor</td>
        <td class="di-colon">:</td>
        <td><strong>{{ $deliveryNote->delivery_note_number }}</strong></td>
      </tr>
      <tr>
        <td class="di-label">Tanggal</td>
        <td class="di-colon">:</td>
        {{-- ✅ Carbon::parse karena stdClass bukan Eloquent --}}
        <td><strong>{{ \Carbon\Carbon::parse($deliveryNote->delivery_note_date)->translatedFormat('d F Y') }}</strong></td>
      </tr>
    </table>
  </div>

  {{-- ===================== CUSTOMER BOX ===================== --}}
  <table class="customer-box">
    <tr>
      <td class="col-left">
        <div class="cbox-label">Kepada Yth :</div>
        {{-- ✅ recipient_name sudah di-set di controller = supplier_name --}}
        <div>{{ $deliveryNote->recipient_name ?? '-' }}</div>
        @if(!empty($deliveryNote->supplier_address))
          <div style="font-size:9pt; color:#444; margin-top:2px;">
            {{ $deliveryNote->supplier_address }}
          </div>
        @endif
      </td>

      <td class="col-right">
        <table class="cbox-inner">
          <tr>
            <td class="ci-label">No. Surat Perintah Kerja / SP</td>
            <td class="ci-colon">:</td>
            {{-- ✅ po_number dari JOIN ke supplier_purchase_orders --}}
            <td>{{ $deliveryNote->po_number ?? '-' }}</td>
          </tr>
          <tr>
            <td class="ci-label">Paket Pekerjaan</td>
            <td class="ci-colon">:</td>
            {{-- ✅ project_name dari JOIN ke activity_types --}}
            <td>{{ $deliveryNote->project_name ?? '-' }}</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>

  {{-- ===================== TABEL BARANG ===================== --}}
  <table class="items-table">
    <thead>
      <tr>
        <th style="width:30px;">No</th>
        <th>Nama Barang</th>
        <th style="width:130px;">Spesifikasi / Batch</th>
        <th colspan="2" style="width:90px;">Jumlah</th>
        <th style="width:100px;">Keterangan</th>
      </tr>
    </thead>
    <tbody>
      {{-- ✅ Pakai $items (Collection dari controller), bukan $deliveryNote->items --}}
      @forelse($items as $i => $item)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td class="text-left">{{ $item->product_name }}</td>
        {{-- ✅ product_code sudah flat dari LEFT JOIN --}}
        <td>{{ $item->product_code ?? $item->batch_number ?? '' }}</td>
        <td style="width:42px;">{{ number_format($item->quantity) }}</td>
        <td style="width:48px;">{{ $item->unit ?? '-' }}</td>
        <td class="text-left">{{ $item->notes ?? '' }}</td>
      </tr>
      @empty
      <tr>
        <td colspan="6" style="text-align:center; color:#888;">Tidak ada item</td>
      </tr>
      @endforelse
    </tbody>
  </table>

  {{-- ===================== SYARAT DAN KETENTUAN ===================== --}}
  <div class="terms-section">
    <div class="terms-title">Syarat dan Ketentuan :</div>
    @if(!empty($deliveryNote->terms))
      {!! $deliveryNote->terms !!}
    @else
      <ol>
        <li>Apabila barang yang dikirimkan sudah sesuai dengan Surat Pesanan atau Surat Perintah Kerja (SPK) maka tidak dapat dikembalikan dengan alasan apapun.</li>
        <li>Retur dapat dilakukan apabila barang yang dikirim tidak sesuai dengan Surat Pesanan atau Surat Perintah Kerja (SPK).</li>
        <li>Jangka waktu retur adalah sebelum Berita Acara Serah Terima ditandatangani kedua belah pihak, apabila pemesanan dilakukan tanpa BAST maka jangka waktu retur adalah maksimal 3 hari sejak Surat Jalan ditandatangani.</li>
      </ol>
    @endif
  </div>

  {{-- ===================== TANDA TANGAN ===================== --}}
  <div class="signature-section">

    <div class="signed-date">
      {{-- ✅ Fallback: signed_city → company->city → kosong --}}
      {{ $deliveryNote->signed_city ?? $company->city ?? '' }},
      {{-- ✅ Carbon::parse karena stdClass --}}
      @if(!empty($deliveryNote->signed_at))
        {{ \Carbon\Carbon::parse($deliveryNote->signed_at)->translatedFormat('d F Y') }}
      @else
        {{ \Carbon\Carbon::parse($deliveryNote->delivery_note_date)->translatedFormat('d F Y') }}
      @endif
    </div>

    <div class="sig-row">

      {{-- Kolom kiri: TTD pejabat --}}
      <div class="sig-col">
        <div class="sig-stamp">
          @if(!empty($deliveryNote->signature_image_base64))
            <img src="{{ $deliveryNote->signature_image_base64 }}" alt="Tanda Tangan">
          @endif
        </div>
        <div class="sig-name">
          {{ $deliveryNote->signed_name ?? $deliveryNote->creator_name ?? $company->pic_name ?? '' }}
        </div>
        @if(!empty($deliveryNote->signed_position))
          <div class="sig-position">{{ $deliveryNote->signed_position }}</div>
        @endif
      </div>

      {{-- Kolom tengah: Pengirim --}}
      <div class="sig-col center">
        <div class="sig-role">Pengirim</div>
        <div class="sig-blank">
          (&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)
        </div>
      </div>

      {{-- Kolom kanan: Penerima --}}
      <div class="sig-col right">
        <div class="sig-role">Penerima,</div>
        <div class="sig-blank">
          (&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;)
        </div>
      </div>

    </div>
  </div>

</body>
</html>
