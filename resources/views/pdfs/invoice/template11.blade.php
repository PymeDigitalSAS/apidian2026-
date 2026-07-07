@php
    // formato de moneda del diseño: $35,000.00
    $t11m = function ($v) { return '$' . number_format((float) $v, 2); };
    $t11taxes = collect($request->tax_totals ?? []);
    $t11ret = isset($withHoldingTaxTotal) ? collect($withHoldingTaxTotal)->sum('tax_amount') : 0;
    $t11lmt = $request->legal_monetary_totals ?? [];
    $t11descuento = (float) ($t11lmt['allowance_total_amount'] ?? 0);
    $t11cargos = (float) ($t11lmt['charge_total_amount'] ?? 0);
@endphp
<body>
    @if(isset($request->head_note))
        <p style="text-align: center; font-size: 8px; margin: 0 0 5px 0;"><strong>{{$request->head_note}}</strong></p>
    @endif

    {{-- Datos del emisor / cliente --}}
    <table width="100%" cellpadding="2" style="font-size: 9px; border-top: 1.2px solid #111111;">
        <tr>
            <td style="width: 50%; text-align: center; border-right: 0.6px solid #111111; border-bottom: 0.6px solid #111111; padding: 5px 8px 3px 8px;">
                <span style="font-size: 12px; font-weight: bold;">DATOS DEL EMISOR</span>
            </td>
            <td style="width: 50%; text-align: center; border-bottom: 0.6px solid #111111; padding: 5px 8px 3px 8px;">
                <span style="font-size: 12px; font-weight: bold;">DATOS DEL CLIENTE</span>
            </td>
        </tr>
        <tr>
            <td style="vertical-align: top; border-right: 0.6px solid #111111; padding: 2px 8px 8px 8px;">
                <table width="100%" style="font-size: 9px;">
                    <tr>
                        <td style="width: 28%; padding: 1px 0;">Nombre</td>
                        <td style="padding: 1px 0;">{{$user->name}}</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0;">NIT/Cédula</td>
                        <td style="padding: 1px 0;">{{$company->identification_number}}-{{$company->dv}}</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0; vertical-align: top;">Obligación</td>
                        <td style="padding: 1px 0;">{{$company->type_liability_names ?? optional($company->type_liability)->name}}</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0;">Email</td>
                        <td style="padding: 1px 0;">{{$request->establishment_email ?? $user->email}}</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0;">Teléfono</td>
                        <td style="padding: 1px 0;">{{$request->establishment_phone ?? $company->phone}}</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0; vertical-align: top;">Dirección</td>
                        <td style="padding: 1px 0;">{{$request->establishment_address ?? $company->address}}</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0;">Ciudad, Depart.</td>
                        <td style="padding: 1px 0;">{{optional($company->municipality)->name}}@if(optional(optional($company->municipality)->department)->name), {{$company->municipality->department->name}}@endif (CO)</td>
                    </tr>
                </table>
            </td>
            <td style="vertical-align: top; padding: 2px 8px 8px 8px;">
                <table width="100%" style="font-size: 9px;">
                    <tr>
                        <td style="width: 28%; padding: 1px 0;">Nombre</td>
                        <td style="padding: 1px 0;">{{$customer->name}}</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0;">NIT/Cédula</td>
                        <td style="padding: 1px 0;">{{$customer->company->identification_number ?? ''}}@if(!empty($request->customer['dv']))-{{$request->customer['dv']}}@endif</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0; vertical-align: top;">Obligación</td>
                        <td style="padding: 1px 0;">{{optional(optional($customer->company)->type_liability)->name ?? 'No aplica'}}</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0;">Email</td>
                        <td style="padding: 1px 0;">{{$request->customer['email'] ?? ''}}</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0;">Teléfono</td>
                        <td style="padding: 1px 0;">{{$request->customer['phone'] ?? ''}}</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0; vertical-align: top;">Dirección</td>
                        <td style="padding: 1px 0;">{{$request->customer['address'] ?? ''}}</td>
                    </tr>
                    <tr>
                        <td style="padding: 1px 0;">Ciudad, Depart.</td>
                        <td style="padding: 1px 0;">{{optional(optional($customer->company)->municipality)->name}}@if(optional(optional(optional($customer->company)->municipality)->department)->name), {{$customer->company->municipality->department->name}}@endif (CO)</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Sector salud: periodo facturado e informacion referencial de usuarios --}}
    @isset($healthfields)
        <p style="font-size: 8px; margin: 10px 0 0 0;">
            <strong>Periodo facturado:</strong> {{$healthfields->invoice_period_start_date}} a {{$healthfields->invoice_period_end_date}}
        </p>
        @if($healthfields->print_users_info_to_pdf)
            <table width="100%" cellpadding="3" style="border-collapse: collapse; font-size: 7.5px; margin-top: 4px;">
                <thead>
                    <tr>
                        <th colspan="5" style="border-top: 1.2px solid #111111; text-align: left; padding-top: 4px;">INFORMACIÓN REFERENCIAL SECTOR SALUD</th>
                    </tr>
                    <tr>
                        <th style="border-bottom: 1px solid #111111; width: 12%; text-align: left; font-weight: bold;">COD PRESTADOR</th>
                        <th style="border-bottom: 1px solid #111111; width: 25%; text-align: left; font-weight: bold;">DATOS USUARIO</th>
                        <th style="border-bottom: 1px solid #111111; width: 25%; text-align: left; font-weight: bold;">INFO. CONTRAT./COBERTURA</th>
                        <th style="border-bottom: 1px solid #111111; width: 20%; text-align: left; font-weight: bold;">NROS. AUTORIZ./MIPRES</th>
                        <th style="border-bottom: 1px solid #111111; width: 18%; text-align: left; font-weight: bold;">INFO. DE PAGOS</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($healthfields->users_info as $item)
                        <tr>
                            <td style="border-bottom: 0.4px solid #cccccc; vertical-align: top; font-size: 7px;">{{$item->provider_code}}</td>
                            <td style="border-bottom: 0.4px solid #cccccc; vertical-align: top; font-size: 7px;">
                                @if($item->identification_number && $item->first_name && $item->surname && $item->health_type_document_identification_id)
                                    Nro ID: {{$item->identification_number}}<br>
                                    Nombre: {{$item->first_name}} {{$item->middle_name}} {{$item->surname}} {{$item->second_surname}}<br>
                                    Tipo Documento: {{$item->health_type_document_identification()->name}}<br>
                                    Tipo Usuario: {{$item->health_type_user()->name}}
                                @endif
                            </td>
                            <td style="border-bottom: 0.4px solid #cccccc; vertical-align: top; font-size: 7px;">
                                Modalidad Contratación: {{$item->health_contracting_payment_method()->name}}<br>
                                Nro. Contrato: {{$item->contract_number}}<br>
                                Cobertura: {{$item->health_coverage()->name}}
                            </td>
                            <td style="border-bottom: 0.4px solid #cccccc; vertical-align: top; font-size: 7px;">
                                Nros Autorización: {{$item->autorization_numbers}}<br>
                                Nro MIPRES: {{$item->mipres}}<br>
                                Entrega MIPRES: {{$item->mipres_delivery}}<br>
                                Nro Poliza: {{$item->policy_number}}
                            </td>
                            <td style="border-bottom: 0.4px solid #cccccc; vertical-align: top; font-size: 7px;">
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
    <table width="100%" cellpadding="4" style="border-collapse: collapse; font-size: 9px; margin-top: 14px;">
        <thead>
            <tr>
                <th style="border-top: 1.2px solid #111111; border-bottom: 1px solid #111111; text-align: left; width: 8%;">REF</th>
                <th style="border-top: 1.2px solid #111111; border-bottom: 1px solid #111111; text-align: left;">DESCRIPCIÓN</th>
                <th style="border-top: 1.2px solid #111111; border-bottom: 1px solid #111111; text-align: right; width: 9%;">CANT.</th>
                <th style="border-top: 1.2px solid #111111; border-bottom: 1px solid #111111; text-align: right; width: 13%;">PRECIO</th>
                <th style="border-top: 1.2px solid #111111; border-bottom: 1px solid #111111; text-align: left; width: 10%;">IMP.</th>
                <th style="border-top: 1.2px solid #111111; border-bottom: 1px solid #111111; text-align: right; width: 13%;">SUBTOTAL</th>
                <th style="border-top: 1.2px solid #111111; border-bottom: 1px solid #111111; text-align: right; width: 13%;">TOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach($request->invoice_lines as $line)
                @php
                    $t11lineTax = collect($line['tax_totals'] ?? [])->sum('tax_amount');
                    $t11linePercent = $line['tax_totals'][0]['percent'] ?? 0;
                @endphp
                <tr>
                    <td style="border-bottom: 0.4px solid #cccccc; vertical-align: top;">{{$line['code'] ?? ''}}</td>
                    <td style="border-bottom: 0.4px solid #cccccc; vertical-align: top; font-size: 8px;">
                        {{$line['description'] ?? ''}}
                        @if(!empty($line['notes']))
                            <br><span style="font-size: 7.5px; font-style: italic; color: #555555;">{{$line['notes']}}</span>
                        @endif
                    </td>
                    <td style="border-bottom: 0.4px solid #cccccc; text-align: right; vertical-align: top;">{{ number_format((float) ($line['invoiced_quantity'] ?? 0), 2) }}</td>
                    <td style="border-bottom: 0.4px solid #cccccc; text-align: right; vertical-align: top;">{{$t11m($line['price_amount'] ?? 0)}}</td>
                    <td style="border-bottom: 0.4px solid #cccccc; vertical-align: top;">IVA {{ rtrim(rtrim(number_format((float) $t11linePercent, 2, '.', ''), '0'), '.') }}%</td>
                    <td style="border-bottom: 0.4px solid #cccccc; text-align: right; vertical-align: top;">{{$t11m($line['line_extension_amount'] ?? 0)}}</td>
                    <td style="border-bottom: 0.4px solid #cccccc; text-align: right; vertical-align: top;">{{$t11m((float) ($line['line_extension_amount'] ?? 0) + $t11lineTax)}}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totales --}}
    <table width="100%" style="margin-top: 12px; font-size: 9px;">
        <tr>
            <td style="width: 55%;"></td>
            <td style="width: 45%; vertical-align: top; padding: 0;">
                <table width="100%" cellpadding="3" style="font-size: 9px; background-color: #fafafa;">
                    <tr>
                        <td>Subtotal</td>
                        <td style="text-align: right;">{{$t11m($t11lmt['tax_exclusive_amount'] ?? 0)}}</td>
                    </tr>
                    @if($t11descuento > 0)
                        <tr>
                            <td>Descuentos (-)</td>
                            <td style="text-align: right;">{{$t11m($t11descuento)}}</td>
                        </tr>
                    @endif
                    @if($t11cargos > 0)
                        <tr>
                            <td>Cargos (+)</td>
                            <td style="text-align: right;">{{$t11m($t11cargos)}}</td>
                        </tr>
                    @endif
                    @foreach($t11taxes as $t11tax)
                        <tr>
                            <td>{{ optional(\App\Tax::find($t11tax['tax_id'] ?? null))->name ?? 'Impuesto' }} {{ rtrim(rtrim(number_format((float) ($t11tax['percent'] ?? 0), 2, '.', ''), '0'), '.') }}%</td>
                            <td style="text-align: right;">{{$t11m($t11tax['tax_amount'] ?? 0)}}</td>
                        </tr>
                    @endforeach
                    @if($t11ret > 0)
                        <tr>
                            <td>Retenciones (-)</td>
                            <td style="text-align: right;">{{$t11m($t11ret)}}</td>
                        </tr>
                    @endif
                </table>
                <table width="100%" cellpadding="3" style="font-size: 14px; margin-top: 4px; border-top: 1px solid #111111;">
                    <tr>
                        <td style="font-weight: bold;">Total</td>
                        <td style="text-align: right; font-weight: bold;">{{$t11m($t11lmt['payable_amount'] ?? 0)}}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Observaciones / leyendas --}}
    @if(!empty($request->notes))
        <p style="font-size: 8px; color: #222222; margin: 14px 0 0 0; line-height: 1.6;">{!! nl2br(e($request->notes)) !!}</p>
    @endif
    <p style="font-size: 7px; color: #444444; margin: 10px 0 0 0; line-height: 1.5;">
        Resolución de Facturación Electrónica Nro. {{$resolution->resolution}}@if(!empty($resolution->resolution_date)) de {{$resolution->resolution_date}}@endif ·
        Prefijo: {{$resolution->prefix}} · Rango: {{$resolution->from}} al {{$resolution->to}}@if(!empty($resolution->date_from) || !empty($resolution->date_to)) · Vigencia: {{$resolution->date_from}} hasta {{$resolution->date_to}}@endif
    </p>
</body>
