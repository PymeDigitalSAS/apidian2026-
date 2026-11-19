@php
    $t14m = function ($v) {
        return '$'.number_format((float) $v, 2, ',', '.');
    };
    $t14pf = isset($paymentForm) ? collect($paymentForm)->first() : null;
    $t14taxes = collect($request->tax_totals ?? []);
    $t14impuestos = (float) $t14taxes->sum('tax_amount');
    $t14ret = isset($withHoldingTaxTotal) ? (float) collect($withHoldingTaxTotal)->sum('tax_amount') : 0;
    $t14lmt = $request->legal_monetary_totals ?? [];
    $t14descuento = (float) ($t14lmt['allowance_total_amount'] ?? 0);
    $t14cargos = (float) ($t14lmt['charge_total_amount'] ?? 0);
    $t14subtotal = (float) ($t14lmt['line_extension_amount'] ?? 0);
    $t14total = (float) ($t14lmt['payable_amount'] ?? 0);
    $t14notas = trim((string) ($notes ?: ($request->notes ?? '')));
@endphp
<body>
    <div style="padding: 4mm 14mm 0 14mm; font-size: 10px; color: #1d2430;">
        @if(!empty($request->head_note))
            <p style="text-align: center; font-size: 8px; margin: 0 0 8px 0;">{{ $request->head_note }}</p>
        @endif

        <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 8mm;">
            <tr>
                <td style="width: 50%; vertical-align: top; padding-right: 12px;">
                    <div style="font-size: 13px; font-weight: bold; margin-bottom: 4px;">Datos del cliente</div>
                    <div style="font-size: 10px; line-height: 1.45; color: #2a313c;">
                        {{ $customer->name }}<br>
                        @if(!empty(optional($customer->company)->identification_number))
                            {{ $customer->company->identification_number }}@if(!empty($request->customer['dv']))-{{ $request->customer['dv'] }}@endif<br>
                        @endif
                        @if(!empty($request->customer['address']))
                            {{ $request->customer['address'] }}<br>
                        @endif
                        @if(optional(optional($customer->company)->municipality)->name)
                            {{ $customer->company->municipality->name }}<br>
                        @endif
                        @if(!empty($request->customer['phone']))
                            {{ $request->customer['phone'] }}<br>
                        @endif
                        @if(!empty($request->customer['email']))
                            {{ $request->customer['email'] }}
                        @endif
                    </div>
                </td>
                <td style="width: 50%; vertical-align: top; padding-left: 12px;">
                    <div style="font-size: 13px; font-weight: bold; margin-bottom: 4px;">Datos del emisor</div>
                    <div style="font-size: 10px; line-height: 1.45; color: #2a313c;">
                        {{ $user->name }}<br>
                        NIT {{ $company->identification_number }}-{{ $company->dv }}<br>
                        {{ $company->address }}<br>
                        @if(optional($company->municipality)->name)
                            {{ $company->municipality->name }}<br>
                        @endif
                        @if(!empty($company->phone))
                            {{ $company->phone }}<br>
                        @endif
                        @if(!empty($user->email))
                            {{ $user->email }}<br>
                        @endif
                        @include('pdfs.invoice._datos')
                    </div>
                </td>
            </tr>
        </table>

        <table width="100%" cellpadding="7" cellspacing="0" style="border-collapse: collapse; font-size: 10px;">
            <thead>
                <tr>
                    <td style="background-color: #26323E; color: #ffffff; font-weight: bold; font-size: 9px;">PRODUCTO/SERVICIO</td>
                    <td style="background-color: #26323E; color: #ffffff; font-weight: bold; font-size: 9px; text-align: right; width: 18%;">PRECIO</td>
                    <td style="background-color: #26323E; color: #ffffff; font-weight: bold; font-size: 9px; text-align: center; width: 14%;">UNIDAD</td>
                    <td style="background-color: #26323E; color: #ffffff; font-weight: bold; font-size: 9px; text-align: right; width: 18%;">TOTAL</td>
                </tr>
            </thead>
            <tbody>
                @foreach($request->invoice_lines ?? [] as $linea)
                    @php
                        $t14fondo = $loop->iteration % 2 === 1 ? '#DBE5EB' : '#F7F7F7';
                        $t14cant = (float) ($linea['invoiced_quantity'] ?? 0);
                        $t14cantTxt = rtrim(rtrim(number_format($t14cant, 2, ',', '.'), '0'), ',');
                    @endphp
                    <tr>
                        <td bgcolor="{{ $t14fondo }}" style="background-color: {{ $t14fondo }}; vertical-align: top;">
                            {{ $linea['description'] ?? '' }}
                            @if(!empty($linea['notes']))
                                <br><span style="font-size: 8px; color: #5c6570;">{{ $linea['notes'] }}</span>
                            @endif
                        </td>
                        <td bgcolor="{{ $t14fondo }}" style="background-color: {{ $t14fondo }}; text-align: right; vertical-align: top;">{{ $t14m($linea['price_amount'] ?? 0) }}</td>
                        <td bgcolor="{{ $t14fondo }}" style="background-color: {{ $t14fondo }}; text-align: center; vertical-align: top;">{{ $t14cantTxt }}</td>
                        <td bgcolor="{{ $t14fondo }}" style="background-color: {{ $t14fondo }}; text-align: right; vertical-align: top;">{{ $t14m($linea['line_extension_amount'] ?? 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 8mm;">
            <tr>
                <td style="width: 55%; vertical-align: top; padding-right: 16px;">
                    <div style="font-size: 13px; font-weight: bold; margin-bottom: 4px;">Método de pago</div>
                    @if($t14pf)
                        <div style="font-size: 10px; line-height: 1.45;">
                            <strong>{{ $t14pf['name'] ?? '' }}</strong>
                            @if(!empty($t14pf['nameMethod']))
                                <br>{{ $t14pf['nameMethod'] }}
                            @endif
                            @if(!empty($t14pf['payment_due_date']))
                                <br>Vence: {{ $t14pf['payment_due_date'] }}
                            @endif
                        </div>
                    @endif
                    @if($t14notas !== '')
                        <div style="font-size: 13px; font-weight: bold; margin: 8px 0 4px 0;">Términos y condiciones</div>
                        <div style="font-size: 8.5px; line-height: 1.4; color: #2a313c;">{{ $t14notas }}</div>
                    @endif
                    @if(!empty($request->foot_note))
                        <div style="font-size: 8px; margin-top: 6px; color: #5c6570;">{{ $request->foot_note }}</div>
                    @endif
                </td>
                <td style="width: 45%; vertical-align: top;">
                    <table width="100%" cellpadding="3" cellspacing="0" style="font-size: 11px;">
                        <tr>
                            <td style="font-weight: bold;">SUBTOTAL</td>
                            <td style="text-align: right;">{{ $t14m($t14subtotal) }}</td>
                        </tr>
                        @if($t14descuento > 0)
                            <tr>
                                <td style="font-weight: bold;">DESCUENTOS</td>
                                <td style="text-align: right;">-{{ $t14m($t14descuento) }}</td>
                            </tr>
                        @endif
                        @if($t14cargos > 0)
                            <tr>
                                <td style="font-weight: bold;">CARGOS</td>
                                <td style="text-align: right;">{{ $t14m($t14cargos) }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td style="font-weight: bold; border-bottom: 1px solid #0C324A; padding-bottom: 6px;">IMPUESTOS</td>
                            <td style="text-align: right; border-bottom: 1px solid #0C324A; padding-bottom: 6px;">{{ $t14m($t14impuestos) }}</td>
                        </tr>
                        @if($t14ret > 0)
                            <tr>
                                <td style="font-weight: bold;">RETENCIONES</td>
                                <td style="text-align: right;">-{{ $t14m($t14ret) }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td style="font-size: 13px; font-weight: bold; color: #0C324A; padding-top: 6px;">TOTAL</td>
                            <td style="font-size: 13px; font-weight: bold; color: #0C324A; text-align: right; padding-top: 6px;">{{ $t14m($t14total) }}</td>
                        </tr>
                    </table>
                    @if(!empty($firma_facturacion))
                        <div style="text-align: right; margin-top: 8px;">
                            <img src="{{ $firma_facturacion }}" alt="firma" style="max-width: 140px; max-height: 55px;">
                            <div style="font-size: 8px;">{{ $user->name }}</div>
                        </div>
                    @endif
                </td>
            </tr>
        </table>

        @include('pdfs.invoice._letras')
    </div>
</body>
