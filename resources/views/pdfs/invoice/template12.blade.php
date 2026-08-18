@php
    // moneda colombiana: 705.840,00
    $t12n = function ($v) { return number_format((float) $v, 2, ',', '.'); };
    $t12taxes = collect($request->tax_totals ?? []);
    $t12ret = isset($withHoldingTaxTotal) ? collect($withHoldingTaxTotal)->sum('tax_amount') : 0;
    $t12lmt = $request->legal_monetary_totals ?? [];
    $t12descuento = (float) ($t12lmt['allowance_total_amount'] ?? 0);
    $t12cargos = (float) ($t12lmt['charge_total_amount'] ?? 0);
    // base AIU: suma de las lineas Administracion/Imprevisto/Utilidad
    $t12aiu = 0;
    foreach ($request->invoice_lines ?? [] as $t12line) {
        if (in_array($t12line['code'] ?? '', ['ADMINIST', 'IMPREVIS', 'UTILIDAD'])) $t12aiu += (float) ($t12line['line_extension_amount'] ?? 0);
    }
    $t12aiuSb = (float) ($t12lmt['line_extension_amount'] ?? 0) - $t12aiu;
    $t12aiuPct = $t12aiuSb > 0 ? round($t12aiu / $t12aiuSb * 100, 2) : 0;
    // ENVIAR A: usar tercero de entrega si viene, si no el cliente
    $t12ship = $request->deliveryparty ?? null;
@endphp
<body>
    @if(isset($request->head_note))
        <p style="text-align: center; color: #2E75B6; font-size: 8px; margin: 0 0 6px 0;"><strong>{{$request->head_note}}</strong></p>
    @endif

    {{-- FACTURAR A / ENVIAR A --}}
    <table width="100%" style="font-size: 9px;">
        <tr>
            <td style="width: 48%; vertical-align: top; padding: 0;">
                <table width="100%" cellpadding="3">
                    <tr><td style="background-color: #2E75B6; color: #ffffff; font-weight: bold;">FACTURAR A:</td></tr>
                </table>
                <div style="padding: 4px 3px 0 3px; line-height: 1.55;">
                    {{$customer->name}}<br>
                    CC o NIT: {{$customer->company->identification_number ?? ''}}@if(!empty($request->customer['dv']))-{{$request->customer['dv']}}@endif<br>
                    {{$request->customer['address'] ?? ''}}<br>
                    {{optional(optional($customer->company)->municipality)->name}}<br>
                    @if(!empty($request->customer['phone'])){{$request->customer['phone']}}<br>@endif
                    @if(!empty($request->customer['email'])){{$request->customer['email']}}@endif
                </div>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%; vertical-align: top; padding: 0;">
                <table width="100%" cellpadding="3">
                    <tr><td style="background-color: #2E75B6; color: #ffffff; font-weight: bold;">ENVIAR A:</td></tr>
                </table>
                <div style="padding: 4px 3px 0 3px; line-height: 1.55;">
                    @if($t12ship)
                        {{$t12ship['name'] ?? ''}}<br>
                        @if(!empty($t12ship['identification_number']))CC o NIT: {{$t12ship['identification_number']}}<br>@endif
                        {{$t12ship['address'] ?? ($request->delivery['address'] ?? '')}}<br>
                        @if(!empty($t12ship['phone'])){{$t12ship['phone']}}<br>@endif
                        @if(!empty($t12ship['email'])){{$t12ship['email']}}@endif
                    @elseif(!empty($request->delivery['address']))
                        {{$customer->name}}<br>
                        {{$request->delivery['address']}}
                    @else
                        {{$customer->name}}<br>
                        CC o NIT: {{$customer->company->identification_number ?? ''}}<br>
                        {{$request->customer['address'] ?? ''}}<br>
                        {{optional(optional($customer->company)->municipality)->name}}
                    @endif
                </div>
            </td>
        </tr>
    </table>

    {{-- Sector salud --}}
    @isset($healthfields)
        <p style="font-size: 8px; color: #333333; margin: 10px 0 0 0;">
            <strong>Periodo facturado:</strong> {{$healthfields->invoice_period_start_date}} a {{$healthfields->invoice_period_end_date}}
        </p>
        @if($healthfields->print_users_info_to_pdf)
            <table width="100%" cellpadding="3" style="border-collapse: collapse; font-size: 7.5px; margin-top: 4px;">
                <thead>
                    <tr><th colspan="5" style="background-color: #2E75B6; color: #ffffff; text-align: center;">INFORMACIÓN REFERENCIAL SECTOR SALUD</th></tr>
                    <tr>
                        <th style="border: 0.5px solid #cccccc; width: 12%; text-align: center; font-weight: normal;">Cod Prestador</th>
                        <th style="border: 0.5px solid #cccccc; width: 25%; text-align: center; font-weight: normal;">Datos Usuario</th>
                        <th style="border: 0.5px solid #cccccc; width: 25%; text-align: center; font-weight: normal;">Info. Contrat./Cobertura</th>
                        <th style="border: 0.5px solid #cccccc; width: 20%; text-align: center; font-weight: normal;">Nros. Autoriz./MIPRES</th>
                        <th style="border: 0.5px solid #cccccc; width: 18%; text-align: center; font-weight: normal;">Info. de Pagos</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($healthfields->users_info as $item)
                        <tr>
                            <td style="border: 0.5px solid #cccccc; vertical-align: top; font-size: 7px;">{{$item->provider_code}}</td>
                            <td style="border: 0.5px solid #cccccc; vertical-align: top; font-size: 7px;">
                                @if($item->identification_number && $item->first_name && $item->surname && $item->health_type_document_identification_id)
                                    Nro ID: {{$item->identification_number}}<br>
                                    Nombre: {{$item->first_name}} {{$item->middle_name}} {{$item->surname}} {{$item->second_surname}}<br>
                                    Tipo Documento: {{$item->health_type_document_identification()->name}}<br>
                                    Tipo Usuario: {{$item->health_type_user()->name}}
                                @endif
                            </td>
                            <td style="border: 0.5px solid #cccccc; vertical-align: top; font-size: 7px;">
                                Modalidad Contratación: {{$item->health_contracting_payment_method()->name}}<br>
                                Nro. Contrato: {{$item->contract_number}}<br>
                                Cobertura: {{$item->health_coverage()->name}}
                            </td>
                            <td style="border: 0.5px solid #cccccc; vertical-align: top; font-size: 7px;">
                                Nros Autorización: {{$item->autorization_numbers}}<br>
                                Nro MIPRES: {{$item->mipres}}<br>
                                Entrega MIPRES: {{$item->mipres_delivery}}<br>
                                Nro Poliza: {{$item->policy_number}}
                            </td>
                            <td style="border: 0.5px solid #cccccc; vertical-align: top; font-size: 7px;">
                                Copago: {{number_format($item->co_payment, 2)}}<br>
                                Cuota Moderadora: {{number_format($item->moderating_fee, 2)}}<br>
                                Pagos Compartidos: {{number_format($item->shared_payment, 2)}}<br>
                                Anticipos: {{number_format($item->advance_payment, 2)}}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endisset

    {{-- Tabla de items --}}
    <table width="100%" cellpadding="4" style="border-collapse: collapse; font-size: 9px; margin-top: 12px;">
        <thead>
            <tr>
                <th style="background-color: #2E75B6; color: #ffffff; text-align: left;">DESCRIPCIÓN</th>
                <th style="background-color: #2E75B6; color: #ffffff; width: 12%; text-align: center;">CANTIDAD</th>
                <th style="background-color: #2E75B6; color: #ffffff; width: 20%; text-align: center;">PRECIO UNITARIO</th>
                <th style="background-color: #2E75B6; color: #ffffff; width: 20%; text-align: center;">MONTO</th>
            </tr>
        </thead>
        <tbody>
            @foreach($request->invoice_lines as $t12i => $line)
                <tr>
                    <td style="border: 0.5px solid #cccccc; @if($t12i % 2 == 1)background-color: #DDEBF7;@endif vertical-align: top;">
                        @if(!empty($line['code'])){{$line['code']}} - @endif{{$line['description'] ?? ''}}
                        @if(!empty($line['notes']))
                            <br><span style="font-size: 7.5px; font-style: italic; color: #555555;">{{$line['notes']}}</span>
                        @endif
                    </td>
                    <td style="border: 0.5px solid #cccccc; @if($t12i % 2 == 1)background-color: #DDEBF7;@endif text-align: right; vertical-align: top;">{{ rtrim(rtrim(number_format((float) ($line['invoiced_quantity'] ?? 0), 2, ',', '.'), '0'), ',') }}</td>
                    <td style="border: 0.5px solid #cccccc; @if($t12i % 2 == 1)background-color: #DDEBF7;@endif text-align: right; vertical-align: top;">{{$t12n($line['price_amount'] ?? 0)}}</td>
                    <td style="border: 0.5px solid #cccccc; @if($t12i % 2 == 1)background-color: #DDEBF7;@endif text-align: right; vertical-align: top;">{{$t12n($line['line_extension_amount'] ?? 0)}}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Observaciones --}}
    @if(!empty($request->notes))
        <table width="100%" cellpadding="5" style="border: 0.5px solid #DDEBF7; margin-top: 8px; font-size: 8px;">
            <tr><td><strong style="color: #2E75B6;">OBSERVACIONES:</strong> <span style="font-style: italic;">{{$request->notes}}</span></td></tr>
        </table>
    @endif

    {{-- GRACIAS + totales --}}
    <table width="100%" style="margin-top: 12px; font-size: 9px;">
        <tr>
            <td style="width: 50%; vertical-align: bottom; padding: 0;">
                <p style="color: #2E75B6; font-size: 15px; font-weight: bold; margin: 0;">GRACIAS</p>
            </td>
            <td style="width: 50%; vertical-align: top; padding: 0;">
                <table width="100%" cellpadding="4" style="border-collapse: collapse;">
                    @if($t12aiu > 0)
                        <tr>
                            <td style="background-color: #DDEBF7; width: 55%;">BASE SERVICIO</td>
                            <td style="background-color: #DDEBF7; text-align: right;">{{$t12n(($t12lmt['line_extension_amount'] ?? 0) - $t12aiu)}}</td>
                        </tr>
                        <tr>
                            <td style="background-color: #DDEBF7;">BASE AIU ({{$t12aiuPct}}%)</td>
                            <td style="background-color: #DDEBF7; text-align: right;">{{$t12n($t12aiu)}}</td>
                        </tr>
                    @else
                        <tr>
                            <td style="background-color: #DDEBF7; width: 55%;">SUBTOTAL</td>
                            <td style="background-color: #DDEBF7; text-align: right;">{{$t12n($t12lmt['line_extension_amount'] ?? 0)}}</td>
                        </tr>
                    @endif
                    @if($t12descuento > 0)
                        <tr>
                            <td style="border: 0.5px solid #DDEBF7;">Descuentos (-)</td>
                            <td style="border: 0.5px solid #DDEBF7; text-align: right;">{{$t12n($t12descuento)}}</td>
                        </tr>
                    @endif
                    @if($t12cargos > 0)
                        <tr>
                            <td style="border: 0.5px solid #DDEBF7;">Cargos (+)</td>
                            <td style="border: 0.5px solid #DDEBF7; text-align: right;">{{$t12n($t12cargos)}}</td>
                        </tr>
                    @endif
                    @foreach($t12taxes as $t12tax)
                        <tr>
                            <td style="background-color: #DDEBF7;">
                                {{ optional(\App\Tax::find($t12tax['tax_id'] ?? null))->name ?? 'Impuestos' }}
                                ({{ rtrim(rtrim(number_format((float) ($t12tax['percent'] ?? 0), 2, '.', ''), '0'), '.') }}%)
                            </td>
                            <td style="background-color: #DDEBF7; text-align: right;">{{$t12n($t12tax['tax_amount'] ?? 0)}}</td>
                        </tr>
                    @endforeach
                    @if($t12ret > 0)
                        <tr>
                            <td style="border: 0.5px solid #DDEBF7;">Retenciones (-)</td>
                            <td style="border: 0.5px solid #DDEBF7; text-align: right;">{{$t12n($t12ret)}}</td>
                        </tr>
                    @endif
                    <tr>
                        <td style="background-color: #2E75B6; color: #ffffff; font-weight: bold;">TOTAL</td>
                        <td style="background-color: #2E75B6; color: #ffffff; font-weight: bold; text-align: right;">$ {{$t12n($t12lmt['payable_amount'] ?? 0)}}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- QR + resolucion --}}
    <table width="100%" style="margin-top: 12px; font-size: 7px; color: #444444;">
        <tr>
            @if(isset($imageQr))
                <td style="width: 120px; vertical-align: top; padding: 0;">
                    <img src="{{$imageQr}}" alt="QR" style="width: 112px; height: 112px;">
                </td>
            @endif
            <td style="vertical-align: top; padding: 0; line-height: 1.5;">
                Resolución de Facturación Electrónica Nro. {{$resolution->resolution}}@if(!empty($resolution->resolution_date)) de {{$resolution->resolution_date}}@endif<br>
                Prefijo: {{$resolution->prefix}} · Rango: {{$resolution->from}} al {{$resolution->to}}@if(!empty($resolution->date_from) || !empty($resolution->date_to))<br>Vigencia: {{$resolution->date_from}} hasta {{$resolution->date_to}}@endif
            </td>
        </tr>
    </table>
</body>
