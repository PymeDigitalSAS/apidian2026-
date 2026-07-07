<table width="100%" style="font-size: 9px;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding: 0;">
            @if(!empty($imgLogo))
                <img src="{{$imgLogo}}" alt="logo" style="max-width: 220px; max-height: 72px; width: auto; height: auto;">
            @else
                <p style="color: #23528c; font-weight: bold; font-size: 15px; margin: 0;">{{$user->name}}</p>
            @endif
        </td>
        <td style="width: 50%; vertical-align: top; text-align: right; padding: 0;">
            @if($request->type_document_id == 3)
                <p style="color: #23528c; font-weight: bold; font-size: 15px; margin: 0;">Factura de Contingencia {{$resolution->prefix}}-{{$request->number}}</p>
            @else
                <p style="color: #23528c; font-weight: bold; font-size: 15px; margin: 0;">Factura {{$resolution->prefix}}-{{$request->number}}</p>
            @endif
            <p style="color: #444444; font-size: 8px; margin: 3px 0 0 0; line-height: 1.5;">
                Fecha de la factura : {{$date}}<br>
                @php $t10pf = isset($paymentForm) ? collect($paymentForm)->first() : null; @endphp
                @if(!empty($t10pf['payment_due_date']))
                    Fecha de vencimiento : {{$t10pf['payment_due_date']}}<br>
                @endif
                Código de cliente : {{$customer->company->identification_number ?? ''}}<br>
                Validación DIAN : {{$date}} {{$time}}
            </p>
        </td>
    </tr>
</table>
