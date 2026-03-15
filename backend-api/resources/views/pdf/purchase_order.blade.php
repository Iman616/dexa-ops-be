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
      font-family: 'Times New Roman', Times, serif;
      font-size: 11pt;
      line-height: 1.4;
      padding: 20px 40px;
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

    .document-title {
      text-align: center;
      font-size: 14pt;
      font-weight: bold;
      margin: 25px 0 20px 0;
      letter-spacing: 2px;
    }

    /* Info Section */
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
      font-size: 9.5pt;
    }

    .info-right {
      display: table-cell;
      width: 50%;
      vertical-align: top;
      padding-left: 30px;
      font-size: 9.5pt;
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
      font-size: 10pt;
    }

    /* Items Table */
    .items-table {
      width: 100%;
      border-collapse: collapse;
      margin: 15px 0;
      font-size: 8.5pt;
    }

    .items-table th {
      border: 1px solid #000;
      padding: 6px 4px;
      text-align: center;
      font-weight: bold;
      background-color: #f2f2f2;
      vertical-align: middle;
      line-height: 1.2;
    }

    .items-table td {
      border: 1px solid #000;
      padding: 5px 6px;
      vertical-align: middle;
    }

    .text-center { text-align: center; }
    .text-right  { text-align: right; }
    .text-left   { text-align: left; }

    /* Summary */
    .summary-row  { text-align: right; font-weight: bold; padding-right: 8px; }
    .summary-value { text-align: right; font-weight: bold; padding-right: 8px; }
    .total-row td { font-weight: bold; background-color: #f9f9f9; }

    /* ✅ Terms Section - sama persis dengan Quotation */
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
  <div class="container">
    {{-- Logo --}}
    @if ($company->logo_path)
    <div class="header-section">
      <img
        src="{{ public_path('storage/' . $company->logo_path) }}"
        alt="Logo {{ $company->company_name }}"
      >
    </div>
    @endif

    {{-- Title --}}
    <div class="document-title">PURCHASE ORDER</div>

    {{-- Customer and PO Info --}}
    <div class="info-section">
      <div class="info-row">
        <div class="info-left">
          <span class="info-label">To</span>: <strong>{{ $customer->customer_name }}</strong>
        </div>
        <div class="info-right">
          Date Purchase&nbsp;&nbsp;&nbsp;: {{ \App\Services\PurchaseOrderPdfService::formatDate($po->po_date) }}
        </div>
      </div>

      <div class="info-row">
        <div class="info-left">
          <span class="info-label"></span>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;{{ $customer->address ?? '' }}
        </div>
        <div class="info-right">
          Number Purchase : {{ $po->po_number }}
        </div>
      </div>

      <div class="info-row">
        <div class="info-left">
          <span class="info-label"></span>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;{{ $customer->city ?? '' }}{{ $customer->province ? ', ' . $customer->province : '' }}
        </div>
        <div class="info-right">
          &nbsp;
        </div>
      </div>

      <div class="info-row">
        <div class="info-left">
          <span class="info-label">Up</span>: {{ $customer->contact_person ?? '-' }}
        </div>
        <div class="info-right">
          Contact person&nbsp;&nbsp;: {{ $customer->contact_person ?? '-' }}
        </div>
      </div>

      <div class="info-row">
        <div class="info-left">
          <span class="info-label">Tlp/Fax</span>: {{ $customer->phone ?? '-' }}
        </div>
        <div class="info-right">
          Phone&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: {{ $customer->phone ?? '-' }}
        </div>
      </div>

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

    {{-- Opening Text --}}
    <div class="opening-text">
      Dengan hormat,
    </div>

    <div class="opening-text">
      Menindaklanjuti penawaran yang sudah diberikan, maka dengan ini kami bermaksud untuk melakukan pemesanan dengan spesifikasi sebagai berikut :
    </div>

    {{-- Items Table --}}
    <table class="items-table">
      <thead>
        <tr>
          <th style="width: 4%;" rowspan="2">No</th>
          <th style="width: 26%;" rowspan="2">Nama Barang</th>
          <th style="width: 12%;" rowspan="2">Brand</th>
          <th style="width: 14%;" rowspan="2">Katalog/Spec</th>
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
          <td class="text-left">{{ $item->product->brand ?? '-' }}</td>
          <td class="text-left">
            {{ $item->product?->product_code ?? '-' }}
            @if ($item->product?->description)
              <br><small style="color:#555;">{{ $item->product->description }}</small>
            @endif
          </td>
          <td class="text-center" style="border-right: none;">{{ number_format($item->quantity, 0, ',', '.') }}</td>
          <td class="text-center" style="border-left: 1px solid #000;">{{ $item->unit }}</td>
          <td class="text-right">Rp&nbsp;&nbsp;&nbsp;&nbsp;{{ number_format($item->unit_price, 0, ',', '.') }}</td>
          <td class="text-center">{{ number_format($item->discount_percent, 1, ',', '.') }}%</td>
          <td class="text-right">Rp&nbsp;&nbsp;&nbsp;&nbsp;{{ number_format($item->total, 0, ',', '.') }}</td>
        </tr>
        @endforeach

        {{-- Summary --}}
        <tr class="summary-row">
          <td class="text-right" colspan="8"><strong>Subtotal</strong></td>
          <td class="text-right"><strong>Rp&nbsp;&nbsp;&nbsp;&nbsp;{{ number_format($subtotal, 0, ',', '.') }}</strong></td>
        </tr>
        <tr class="summary-row">
          <td class="text-right" colspan="8"><strong>PPN {{ $ppn_percent }}%</strong></td>
          <td class="text-right"><strong>Rp&nbsp;&nbsp;&nbsp;&nbsp;{{ number_format($ppn, 0, ',', '.') }}</strong></td>
        </tr>
        <tr class="total-row">
          <td class="text-right" colspan="8"><strong>Total</strong></td>
          <td class="text-right"><strong>Rp&nbsp;&nbsp;&nbsp;&nbsp;{{ number_format($grand_total, 0, ',', '.') }}</strong></td>
        </tr>
      </tbody>
    </table>

    {{-- ✅ Dynamic Terms Section - SAMA dengan Quotation --}}
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

    {{-- Closing Text --}}
    <div class="closing-text">
      Demikian Purchase Order ini dibuat untuk ditindaklanjuti. Atas perhatian dan kerjasamanya, kami ucapkan terima kasih.
    </div>

    {{-- Signature --}}
    <div class="signature-section">
      <div class="signature-box">
        <div class="hormat-kami">Best Regards,</div>
        <div class="signature-stamp">
          @if (in_array($po->status, ['sent', 'issued', 'approved']) && !empty($po->signature_image))
            @php $signaturePath = storage_path('app/public/' . $po->signature_image); @endphp
            @if (file_exists($signaturePath))
              <img src="{{ $signaturePath }}" alt="Signature">
            @endif
          @endif
        </div>
        <div class="sig-name">
          {{ in_array($po->status, ['sent','issued','approved']) && $po->signed_name
              ? $po->signed_name
              : '_________________' }}
        </div>
        <div class="sig-position">
          {{ in_array($po->status, ['sent','issued','approved']) && $po->signed_position
              ? $po->signed_position
              : 'Direktur' }}
        </div>
      </div>
    </div>

  </div>
</body>
</html>
