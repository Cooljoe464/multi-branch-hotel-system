<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - {{ $folio->folio_number }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 12px; color: #333; line-height: 1.5; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #4f46e5; color: white; padding: 24px; border-radius: 8px 8px 0 0; }
        .header h1 { font-size: 22px; font-weight: bold; }
        .header p { font-size: 12px; color: #c7d2fe; margin-top: 4px; }
        .header-right { text-align: right; }
        .header-right h2 { font-size: 18px; font-weight: 600; }
        .details { padding: 24px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; }
        .details h3 { font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 8px; letter-spacing: 0.05em; }
        .details p { font-size: 12px; color: #111; }
        .details .label { color: #6b7280; }
        .stay-info { background: #f9fafb; padding: 16px 24px; border-bottom: 1px solid #e5e7eb; text-align: center; }
        .stay-info .grid { display: flex; justify-content: space-around; }
        .stay-info .item p:first-child { font-size: 11px; color: #6b7280; }
        .stay-info .item p:last-child { font-size: 13px; font-weight: 500; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f9fafb; font-size: 10px; text-transform: uppercase; color: #6b7280; padding: 8px 16px; text-align: left; letter-spacing: 0.05em; border-bottom: 1px solid #e5e7eb; }
        th:last-child, th:nth-child(4) { text-align: right; }
        td { padding: 10px 16px; font-size: 12px; border-bottom: 1px solid #f3f4f6; }
        td:nth-child(4) { text-align: right; color: #dc2626; font-weight: 500; }
        td:nth-child(5) { text-align: right; color: #16a34a; font-weight: 500; }
        .voided { opacity: 0.5; text-decoration: line-through; }
        .summary { padding: 24px; background: #f9fafb; border-top: 1px solid #e5e7eb; }
        .summary .lines { width: 280px; margin-left: auto; }
        .summary .line { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 6px; }
        .summary .line span:first-child { color: #6b7280; }
        .summary .total { border-top: 2px solid #d1d5db; padding-top: 8px; margin-top: 8px; font-size: 16px; font-weight: bold; }
        .footer { text-align: center; padding: 24px; font-size: 11px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="container">
        <table style="width: 100%; margin-bottom: 0;">
            <tr>
                <td style="padding: 24px; background: #4f46e5; color: white; border-radius: 8px 8px 0 0;">
                    <h1 style="font-size: 22px; font-weight: bold; margin: 0;">{{ $branch->name }}</h1>
                    <p style="font-size: 12px; color: #c7d2fe; margin-top: 4px;">{{ $branch->city }}</p>
                    @if($branch->address)
                        <p style="font-size: 12px; color: #c7d2fe;">{{ $branch->address }}</p>
                    @endif
                    @if($branch->phone)
                        <p style="font-size: 12px; color: #c7d2fe; margin-top: 4px;">Tel: {{ $branch->phone }}</p>
                    @endif
                </td>
                <td style="padding: 24px; background: #4f46e5; color: white; text-align: right; border-radius: 0 8px 0 0;">
                    <h2 style="font-size: 18px; font-weight: 600; margin: 0;">INVOICE</h2>
                    <p style="font-size: 12px; color: #c7d2fe; margin-top: 4px; font-family: monospace;">{{ $folio->folio_number }}</p>
                    <p style="font-size: 12px; color: #c7d2fe;">Status: {{ strtoupper($folio->status) }}</p>
                </td>
            </tr>
        </table>

        <div class="details">
            <div>
                <h3>Bill To</h3>
                <p style="font-weight: 500; font-size: 14px;">{{ $guest_name }}</p>
                @if($folio->description)
                    <p style="color: #6b7280; margin-top: 4px;">{{ $folio->description }}</p>
                @endif
            </div>
            <div>
                <h3>Details</h3>
                <p><span class="label">Folio:</span> {{ $folio->folio_number }}</p>
                @if($reservation)
                    <p><span class="label">Confirmation:</span> {{ $reservation->confirmation_number }}</p>
                    @if($reservation->room)
                        <p><span class="label">Room:</span> {{ $reservation->room->number }}</p>
                    @endif
                    @if($reservation->room_type)
                        <p><span class="label">Room Type:</span> {{ $reservation->room_type->name }}</p>
                    @endif
                @endif
                <p><span class="label">Date:</span> {{ $folio->created_at->format('M d, Y') }}</p>
            </div>
        </div>

        @if($reservation)
            <div class="stay-info">
                <div class="grid">
                    <div class="item">
                        <p>Check-in</p>
                        <p>{{ \Carbon\Carbon::parse($reservation->check_in_date)->format('M d, Y') }}</p>
                    </div>
                    <div class="item">
                        <p>Check-out</p>
                        <p>{{ \Carbon\Carbon::parse($reservation->check_out_date)->format('M d, Y') }}</p>
                    </div>
                    <div class="item">
                        <p>Room Rate</p>
                        <p>{{ $branch->currency_symbol }}{{ number_format($reservation->room_rate / 100, 2) }}/night</p>
                    </div>
                </div>
            </div>
        @endif

        <div style="padding: 24px;">
            <h3 style="font-size: 10px; text-transform: uppercase; color: #6b7280; margin-bottom: 12px; letter-spacing: 0.05em;">Itemized Charges</h3>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Debit</th>
                        <th>Credit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($transactions as $tx)
                        <tr class="{{ $tx->is_voided ? 'voided' : '' }}">
                            <td>{{ $tx->created_at->format('M d, Y') }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $tx->category)) }}</td>
                            <td>
                                {{ $tx->description }}
                                @if($tx->is_voided)
                                    <span style="color: #dc2626; font-size: 10px;">(VOIDED)</span>
                                @endif
                            </td>
                            <td>{{ ($tx->type === 'debit' && !$tx->is_voided) ? $branch->currency_symbol . number_format($tx->amount / 100, 2) : '' }}</td>
                            <td>{{ ($tx->type === 'credit' && !$tx->is_voided) ? $branch->currency_symbol . number_format($tx->amount / 100, 2) : '' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 32px; color: #9ca3af;">No transactions.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="summary">
            <div class="lines">
                <div class="line">
                    <span>Total Charges</span>
                    <span>{{ $branch->currency_symbol }}{{ number_format($debits_total / 100, 2) }}</span>
                </div>
                <div class="line">
                    <span>Tax Included</span>
                    <span>{{ $branch->currency_symbol }}{{ number_format($tax_total / 100, 2) }}</span>
                </div>
                <div class="line">
                    <span>Total Payments</span>
                    <span style="color: #16a34a;">{{ $branch->currency_symbol }}{{ number_format($credits_total / 100, 2) }}</span>
                </div>
                <div class="line total" style="color: {{ $balance > 0 ? '#dc2626' : '#16a34a' }};">
                    <span>Balance Due</span>
                    <span>{{ $branch->currency_symbol }}{{ number_format($balance / 100, 2) }}</span>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>Thank you for staying with us.</p>
            <p style="margin-top: 4px;">{{ $branch->name }} &mdash; {{ $branch->city }}</p>
            <p style="margin-top: 4px;">Generated {{ $generated_at }}</p>
        </div>
    </div>
</body>
</html>
