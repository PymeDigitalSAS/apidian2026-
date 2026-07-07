@php
    $t12pf = isset($paymentForm) ? collect($paymentForm)->first() : null;
    $t12cond = $t12pf['name'] ?? '';
    if (!empty($t12pf['payment_due_date'])) {
        $t12cond = trim($t12cond . ' · Vence ' . $t12pf['payment_due_date']);
    }
@endphp
<table width="100%" style="font-size: 9px;">
    <tr>
        <td style="width: 55%; vertical-align: middle; padding: 0;">
            @if(!empty($imgLogo))
                <img src="{{$imgLogo}}" alt="logo" style="max-width: 240px; max-height: 80px; width: auto; height: auto;">
            @else
                <p style="color: #2E75B6; font-weight: bold; font-size: 16px; margin: 8px 0 0 0;">{{$user->name}}</p>
            @endif
        </td>
        <td style="width: 45%; vertical-align: top; text-align: right; padding: 0;">
            @if($request->type_document_id == 3)
                <p style="color: #2E75B6; font-weight: bold; font-size: 22px; margin: 0;">FACTURA</p>
                <p style="color: #2E75B6; font-size: 9px; margin: 0;">ELECTRÓNICA DE CONTINGENCIA</p>
            @else
                <p style="color: #2E75B6; font-weight: bold; font-size: 22px; margin: 0;">FACTURA</p>
                <p style="color: #2E75B6; font-size: 9px; margin: 0;">ELECTRÓNICA DE VENTA</p>
            @endif
        </td>
    </tr>
</table>

<table width="100%" style="font-size: 9px; margin-top: 4px;">
    <tr>
        {{-- Datos del emisor --}}
        <td style="width: 46%; vertical-align: top; padding: 0;">
            <p style="color: #2E75B6; margin: 0; line-height: 1.55;">
                {{$user->name}}<br>
                NIT: {{$company->identification_number}}-{{$company->dv}}<br>
                {{$request->establishment_address ?? $company->address}}<br>
                {{optional($company->municipality)->name}} - {{optional($company->country)->name}}<br>
                {{$request->establishment_phone ?? $company->phone}}<br>
                {{$request->establishment_email ?? $user->email}}
            </p>
        </td>
        <td style="width: 4%;"></td>
        {{-- Bloque de metadatos + QR --}}
        <td style="width: 50%; vertical-align: top; padding: 0;">
            <table width="100%" cellpadding="3" style="font-size: 8.5px; border-collapse: collapse;">
                <tr>
                    <td style="background-color: #2E75B6; color: #ffffff; width: 55%;">N.º DE FACTURA</td>
                    <td style="background-color: #2E75B6; color: #ffffff; width: 45%;">FECHA</td>
                </tr>
                <tr>
                    <td style="border: 0.5px solid #cccccc;">{{$resolution->prefix}}-{{$request->number}}</td>
                    <td style="border: 0.5px solid #cccccc;">{{$date}}</td>
                </tr>
                <tr>
                    <td style="background-color: #2E75B6; color: #ffffff;">ID DE CLIENTE</td>
                    <td style="background-color: #2E75B6; color: #ffffff;">CONDICIONES</td>
                </tr>
                <tr>
                    <td style="border: 0.5px solid #cccccc;">{{$customer->company->identification_number ?? ''}}</td>
                    <td style="border: 0.5px solid #cccccc;">{{$t12cond}}</td>
                </tr>
            </table>
            <p style="margin: 3px 0 0 0; font-size: 6px; color: #444444; line-height: 1.4;">
                @if($request->type_document_id == 3) CUDE: @else CUFE: @endif {{$cufecude}}<br>
                Validación DIAN: {{$date}} {{$time}} · Autorización {{$resolution->resolution}} · {{$resolution->prefix}}{{$resolution->from}} al {{$resolution->prefix}}{{$resolution->to}}
            </p>
        </td>
    </tr>
</table>
