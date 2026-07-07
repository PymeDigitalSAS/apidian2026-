@php
    $t11pf = isset($paymentForm) ? collect($paymentForm)->first() : null;
@endphp
<table width="100%" style="font-size: 9px;">
    <tr>
        <td style="width: 35%; vertical-align: middle; padding: 0;">
            @if(!empty($imgLogo))
                {{-- caja 230x160: los logos verticales escalan por alto y los
                     horizontales por ancho, sin desbordar el encabezado --}}
                <img src="{{$imgLogo}}" alt="logo" style="max-width: 230px; max-height: 160px; width: auto; height: auto;">
            @else
                <p style="font-weight: bold; font-size: 16px; margin: 20px 0 0 0;">{{$user->name}}</p>
            @endif
        </td>
        <td style="width: 65%; vertical-align: top; padding: 0 0 0 8px;">
            <table width="100%">
                <tr>
                    <td style="padding: 0;">
                        @if($request->type_document_id == 3)
                            <span style="font-size: 15px; color: #111111;">Factura Electrónica de Contingencia</span>
                        @else
                            <span style="font-size: 15px; color: #111111;">Factura Electrónica de Venta</span>
                        @endif
                    </td>
                    <td style="padding: 0; text-align: right;">
                        <span style="font-size: 15px; color: #111111;">{{$resolution->prefix}} - {{$request->number}}</span>
                    </td>
                </tr>
            </table>
            <p style="font-size: 6px; color: #333333; margin: 1px 0 0 0; line-height: 1.4;">
                @if($request->type_document_id == 3) CUDE: @else CUFE: @endif {{$cufecude}}<br>
                Autorización Numeración de Facturación<br>
                No. {{$resolution->resolution}} de {{$resolution->date_from}} - {{$resolution->date_to}} autoriza {{$resolution->prefix}}{{$resolution->from}} - {{$resolution->prefix}}{{$resolution->to}}
            </p>
            <table width="100%" style="margin-top: 5px; font-size: 9px;">
                <tr>
                    <td style="width: 34%; padding: 1px 0;">Fecha de Generación</td>
                    <td style="width: 33%; padding: 1px 0; text-align: right;">{{$date}} {{$time}}</td>
                    <td style="width: 33%; text-align: right; vertical-align: top;" rowspan="5">
                        @if(isset($imageQr))
                            <img src="{{$imageQr}}" alt="QR" style="width: 110px; height: 110px;">
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding: 1px 0;">Fecha de Vencimiento</td>
                    <td style="padding: 1px 0; text-align: right;">{{ $t11pf['payment_due_date'] ?? $date }}</td>
                </tr>
                <tr>
                    <td style="padding: 1px 0;">Fecha de Validación</td>
                    <td style="padding: 1px 0; text-align: right;">{{$date}} {{$time}}</td>
                </tr>
                <tr>
                    <td style="padding: 1px 0;">Forma de Pago</td>
                    <td style="padding: 1px 0; text-align: right;">{{ $t11pf['name'] ?? '' }}</td>
                </tr>
                <tr>
                    <td style="padding: 1px 0;">Medio de Pago</td>
                    <td style="padding: 1px 0; text-align: right;">{{ $t11pf['nameMethod'] ?? '' }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
