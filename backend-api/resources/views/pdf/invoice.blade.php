<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .header { text-align: center; margin-bottom: 30px; }
        .company-info { margin-bottom: 20px; }
        .invoice-info { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .text-right { text-align: right; }
        .total-section { margin-top: 20px; }
        .footer { margin-top: 50px; text-align: center; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>INVOICE</h1>
        <h2>{{ $invoice->invoice_number }}</h2>
    </div>

    <!-- Company Info -->
    <div class="company-info">
        <strong>{{ $invoice->company->company_name }}</strong><br>
        {{ $invoice->company->address }}<br>
        Phone: {{ $invoice->company->phone }}<br>
        Email: {{ $invoice->company->email }}
    </div>

    <!-- Invoice Info -->
    <table>
        <tr>
            <td width="50%">
                <strong>Bill To:</strong><br>
                {{ $invoice->customer->customer_name }}<br>
                {{ $invoice->customer->address }}<br>
                Phone: {{ $invoice->customer->phone }}<br>
                Email: {{ $invoice->customer->email }}
            </td>
            <td width="50%">
                <strong>Invoice Date:</strong> {{ $invoice->invoice_date->format('d/m/Y') }}<br>
                <strong>Due Date:</strong> {{ $invoice->due_date->format('d/m/Y') }}<br>
                <strong>Payment Status:</strong> {{ $invoice->payment_status_label }}<br>
                <strong>Currency:</strong> {{ $invoice->currency }}
            </td>
        </tr>
    </table>

    <!-- Items -->
    <table>
        <thead>
            <tr>
                <th width="5%">No</th>
                <th width="40%">Product</th>
                <th width="15%">Qty</th>
                <th width="10%">Unit</th>
                <th width="15%" class="text-right">Unit Price</th>
                <th width="15%" class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>
                    <strong>{{ $item->product_name }}</strong><br>
                    <small>{{ $item->product_description }}</small>
                </td>
                <td>{{ $item->quantity }}</td>
                <td>{{ $item->unit }}</td>
                <td class="text-right">{{ number_format($item->unit_price, 0, ',', '.') }}</td>
                <td class="text-right">{{ number_format($item->quantity * $item->unit_price, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <!-- Total Section -->
    <div class="total-section">
        <table width="40%" style="float: right;">
            <tr>
                <td><strong>Subtotal:</strong></td>
                <td class="text-right">{{ $invoice->currency }} {{ number_format($invoice->subtotal, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td><strong>Discount:</strong></td>
                <td class="text-right">{{ $invoice->currency }} {{ number_format($invoice->discount_amount, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td><strong>Tax ({{ $invoice->tax_percentage }}%):</strong></td>
                <td class="text-right">{{ $invoice->currency }} {{ number_format($invoice->tax_amount, 0, ',', '.') }}</td>
            </tr>
            <tr style="background-color: #f2f2f2;">
                <td><strong>Total:</strong></td>
                <td class="text-right"><strong>{{ $invoice->currency }} {{ number_format($invoice->total_amount, 0, ',', '.') }}</strong></td>
            </tr>
            <tr>
                <td><strong>Paid:</strong></td>
                <td class="text-right" style="color: green;">{{ $invoice->currency }} {{ number_format($invoice->paid_amount, 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td><strong>Remaining:</strong></td>
                <td class="text-right" style="color: red;">{{ $invoice->currency }} {{ number_format($invoice->remaining_amount, 0, ',', '.') }}</td>
            </tr>
        </table>
    </div>

    <div style="clear: both;"></div>

    <!-- Notes -->
    @if($invoice->notes)
    <div style="margin-top: 30px;">
        <strong>Notes:</strong><br>
        {{ $invoice->notes }}
    </div>
    @endif

    <!-- Footer -->
    <div class="footer">
        <p>Thank you for your business!</p>
        <p>Generated on {{ now()->format('d/m/Y H:i') }}</p>
    </div>
</body>
</html>
