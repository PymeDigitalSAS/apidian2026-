@php
    // formato de moneda colombiano: 120.000,00
    $t10n = function ($v) { return number_format((float) $v, 2, ',', '.'); };
    $t10pf = isset($paymentForm) ? collect($paymentForm)->first() : null;
    $t10taxes = collect($request->tax_totals ?? []);
    $t10ret = isset($withHoldingTaxTotal) ? collect($withHoldingTaxTotal)->sum('tax_amount') : 0;
    $t10lmt = $request->legal_monetary_totals ?? [];
    $t10descuento = (float) ($t10lmt['allowance_total_amount'] ?? 0);
    $t10cargos = (float) ($t10lmt['charge_total_amount'] ?? 0);
    // base AIU: suma de las lineas Administracion/Imprevisto/Utilidad
    $t10aiu = 0;
    foreach ($request->invoice_lines ?? [] as $t10line) {
        if (in_array($t10line['code'] ?? '', ['ADMINIST', 'IMPREVIS', 'UTILIDAD'])) $t10aiu += (float) ($t10line['line_extension_amount'] ?? 0);
    }
    $t10aiuSb = (float) ($t10lmt['line_extension_amount'] ?? 0) - $t10aiu;
    $t10aiuPct = $t10aiuSb > 0 ? round($t10aiu / $t10aiuSb * 100, 2) : 0;
@endphp
<body>
    @if(isset($request->head_note))
        <p style="text-align: center; font-size: 8px; margin: 0 0 6px 0;"><strong>{{$request->head_note}}</strong></p>
    @endif

    {{-- Bloques De / A --}}
    <table width="100%" style="font-size: 9px;">
        <tr>
            <td style="width: 48%; vertical-align: top; padding: 0;">
                <p style="color: #666666; font-size: 8px; margin: 0 0 2px 0;">De</p>
                <table width="100%" cellpadding="7" style="background-color: #f0f0f2;">
                    <tr>
                        <td style="font-size: 9px; color: #333333; line-height: 1.5;">
                            <span style="color: #23528c; font-weight: bold; font-size: 11px;">{{$user->name}}</span><br>
                            NIT: {{$company->identification_number}}-{{$company->dv}} · {{$company->type_regime->name ?? ''}}<br>
                            @if(isset($request->establishment_address))
                                {{$request->establishment_address}}<br>
                            @else
                                {{$company->address}}<br>
                            @endif
                            {{optional($company->municipality)->name}} - {{optional($company->country)->name}}<br>
                            <br>
                            Teléfono: {{$request->establishment_phone ?? $company->phone}}<br>
                            Correo: {{$request->establishment_email ?? $user->email}}
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 4%;"></td>
            <td style="width: 48%; vertical-align: top; padding: 0;">
                <p style="color: #666666; font-size: 8px; margin: 0 0 2px 0;">A</p>
                <table width="100%" cellpadding="7" style="border: 0.5px solid #999999;">
                    <tr>
                        <td style="font-size: 9px; color: #333333; line-height: 1.5;">
                            <span style="font-weight: bold; font-size: 11px;">{{$customer->name}}</span><br>
                            CC o NIT: {{$customer->company->identification_number ?? ''}}@if(!empty($request->customer['dv']))-{{$request->customer['dv']}}@endif<br>
                            {{$request->customer['address'] ?? ''}}<br>
                            {{optional(optional($customer->company)->municipality)->name}}<br>
                            @if(!empty($request->customer['phone']))
                                Teléfono: {{$request->customer['phone']}}<br>
                            @endif
                            @if(!empty($request->customer['email']))
                                Correo: {{$request->customer['email']}}<br>
                            @endif
                            @if($t10pf)
                                Forma de pago: {{$t10pf['name'] ?? ''}} · Medio: {{$t10pf['nameMethod'] ?? ''}}
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Sector salud: periodo facturado e informacion referencial de usuarios --}}
    @isset($healthfields)
        <p style="font-size: 8px; color: #333333; margin: 8px 0 0 0;">
            <strong>Periodo facturado:</strong> {{$healthfields->invoice_period_start_date}} a {{$healthfields->invoice_period_end_date}}
        </p>
        @if($healthfields->print_users_info_to_pdf)
            <table width="100%" cellpadding="3" style="border-collapse: collapse; font-size: 7.5px; margin-top: 4px;">
                <thead>
                    <tr>
                        <th colspan="5" style="border: 0.5px solid #666666; text-align: center;">INFORMACIÓN REFERENCIAL SECTOR SALUD</th>
                    </tr>
                    <tr>
                        <th style="border: 0.5px solid #666666; width: 12%; text-align: center; font-weight: normal;">Cod Prestador</th>
                        <th style="border: 0.5px solid #666666; width: 25%; text-align: center; font-weight: normal;">Datos Usuario</th>
                        <th style="border: 0.5px solid #666666; width: 25%; text-align: center; font-weight: normal;">Info. Contrat./Cobertura</th>
                        <th style="border: 0.5px solid #666666; width: 20%; text-align: center; font-weight: normal;">Nros. Autoriz./MIPRES</th>
                        <th style="border: 0.5px solid #666666; width: 18%; text-align: center; font-weight: normal;">Info. de Pagos</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($healthfields->users_info as $item)
                        <tr>
                            <td style="border: 0.5px solid #666666; vertical-align: top; font-size: 7px;">{{$item->provider_code}}</td>
                            <td style="border: 0.5px solid #666666; vertical-align: top; font-size: 7px;">
                                @if($item->identification_number && $item->first_name && $item->surname && $item->health_type_document_identification_id)
                                    Nro ID: {{$item->identification_number}}<br>
                                    Nombre: {{$item->first_name}} {{$item->middle_name}} {{$item->surname}} {{$item->second_surname}}<br>
                                    Tipo Documento: {{$item->health_type_document_identification()->name}}<br>
                                    Tipo Usuario: {{$item->health_type_user()->name}}
                                @endif
                            </td>
                            <td style="border: 0.5px solid #666666; vertical-align: top; font-size: 7px;">
                                Modalidad Contratación: {{$item->health_contracting_payment_method()->name}}<br>
                                Nro. Contrato: {{$item->contract_number}}<br>
                                Cobertura: {{$item->health_coverage()->name}}
                            </td>
                            <td style="border: 0.5px solid #666666; vertical-align: top; font-size: 7px;">
                                Nros Autorización: {{$item->autorization_numbers}}<br>
                                Nro MIPRES: {{$item->mipres}}<br>
                                Entrega MIPRES: {{$item->mipres_delivery}}<br>
                                Nro Poliza: {{$item->policy_number}}
                            </td>
                            <td style="border: 0.5px solid #666666; vertical-align: top; font-size: 7px;">
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
    <p style="text-align: right; font-size: 7.5px; color: #444444; margin: 10px 0 1px 0;">Importes visualizados en Peso Colombiano (COP)</p>
    <table width="100%" cellpadding="4" style="border-collapse: collapse; font-size: 9px;">
        <thead>
            <tr>
                <th style="border: 0.5px solid #666666; text-align: left; font-weight: normal;">Descripción</th>
                <th style="border: 0.5px solid #666666; width: 8%; text-align: center; font-weight: normal;">IVA</th>
                <th style="border: 0.5px solid #666666; width: 14%; text-align: center; font-weight: normal;">P.U.</th>
                <th style="border: 0.5px solid #666666; width: 8%; text-align: center; font-weight: normal;">Cant.</th>
                <th style="border: 0.5px solid #666666; width: 16%; text-align: center; font-weight: normal;">Total (sin imp.)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($request->invoice_lines as $line)
                <tr>
                    <td style="border-left: 0.5px solid #666666; border-right: 0.5px solid #666666; border-bottom: 0.5px solid #dddddd; vertical-align: top;">
                        @if(!empty($line['code'])){{$line['code']}} - @endif{{$line['description'] ?? ''}}
                        @if(!empty($line['notes']))
                            <br><span style="font-size: 7.5px; font-style: italic; color: #555555;">{{$line['notes']}}</span>
                        @endif
                        @if(!empty($line['lot_code']) || !empty($line['date_of_due']))
                            <br><span style="font-size: 7.5px; font-style: italic; color: #555555;">
                                @if(!empty($line['lot_code']))<strong>Lote:</strong> {{$line['lot_code']}}@endif
                                @if(!empty($line['lot_code']) && !empty($line['date_of_due'])) | @endif
                                @if(!empty($line['date_of_due']))<strong>Vence:</strong> {{$line['date_of_due']}}@endif
                            </span>
                        @endif
                        @php $lineAttrs = array_filter(is_array($line['item_attributes'] ?? null) ? $line['item_attributes'] : [], function ($a) { return !empty($a['value']); }); @endphp
                        @if(count($lineAttrs))
                            <br><span style="font-size: 7.5px; font-style: italic; color: #555555;">@foreach($lineAttrs as $attr)<strong>{{$attr['description'] ?? ''}}:</strong> {{$attr['value']}}@if(!$loop->last) | @endif @endforeach</span>
                        @endif
                    </td>
                    <td style="border-left: 0.5px solid #666666; border-right: 0.5px solid #666666; border-bottom: 0.5px solid #dddddd; text-align: right; vertical-align: top;">{{ rtrim(rtrim(number_format((float) ($line['tax_totals'][0]['percent'] ?? 0), 2, '.', ''), '0'), '.') }}%</td>
                    <td style="border-left: 0.5px solid #666666; border-right: 0.5px solid #666666; border-bottom: 0.5px solid #dddddd; text-align: right; vertical-align: top;">{{$t10n($line['price_amount'] ?? 0)}}</td>
                    <td style="border-left: 0.5px solid #666666; border-right: 0.5px solid #666666; border-bottom: 0.5px solid #dddddd; text-align: right; vertical-align: top;">{{ rtrim(rtrim(number_format((float) ($line['invoiced_quantity'] ?? 0), 2, ',', '.'), '0'), ',') }}</td>
                    <td style="border-left: 0.5px solid #666666; border-right: 0.5px solid #666666; border-bottom: 0.5px solid #dddddd; text-align: right; vertical-align: top;">{{$t10n($line['line_extension_amount'] ?? 0)}}</td>
                </tr>
            @endforeach
            <tr>
                <td style="border: 0.5px solid #666666; border-top: 0.5px solid #dddddd;" colspan="1"></td>
                <td style="border: 0.5px solid #666666; border-top: 0.5px solid #dddddd;"></td>
                <td style="border: 0.5px solid #666666; border-top: 0.5px solid #dddddd;"></td>
                <td style="border: 0.5px solid #666666; border-top: 0.5px solid #dddddd;"></td>
                <td style="border: 0.5px solid #666666; border-top: 0.5px solid #dddddd;"></td>
            </tr>
        </tbody>
    </table>

    {{-- Observaciones --}}
    @if(!empty($request->notes))
        <table width="100%" cellpadding="6" style="border: 0.5px solid #bbbbbb; margin-top: 8px; font-size: 8px;">
            <tr>
                <td>
                    <strong>OBSERVACIONES:</strong><br>
                    <span style="font-style: italic;">{{$request->notes}}</span>
                </td>
            </tr>
        </table>
    @endif

    {{-- QR + resolución a la izquierda, totales a la derecha --}}
    <table width="100%" style="margin-top: 10px; font-size: 9px;">
        <tr>
            <td style="width: 55%; vertical-align: top; padding: 0;">
                @if(isset($imageQr))
                    <img src="{{$imageQr}}" alt="QR" style="width: 120px; height: 120px;">
                @endif
                <p style="font-size: 7px; color: #444444; margin: 4px 0 0 0; line-height: 1.5;">
                    Resolución de Facturación Electrónica Nro. {{$resolution->resolution}}@if(!empty($resolution->resolution_date)) de {{$resolution->resolution_date}}@endif<br>
                    Prefijo: {{$resolution->prefix}} · Rango: {{$resolution->from}} al {{$resolution->to}}<br>
                    @if(!empty($resolution->date_from) || !empty($resolution->date_to))
                        Vigencia: {{$resolution->date_from}} hasta {{$resolution->date_to}}
                    @endif
                </p>
            </td>
            <td style="width: 45%; vertical-align: top; padding: 0;">
                <table width="100%" cellpadding="4" style="font-size: 9px;">
                    @if($t10aiu > 0)
                        <tr>
                            <td style="border-bottom: 0.5px solid #dddddd;">Base servicio</td>
                            <td style="border-bottom: 0.5px solid #dddddd; text-align: right;">{{$t10n(($t10lmt['line_extension_amount'] ?? 0) - $t10aiu)}}</td>
                        </tr>
                        <tr>
                            <td style="border-bottom: 0.5px solid #dddddd;">Base AIU ({{$t10aiuPct}}%)</td>
                            <td style="border-bottom: 0.5px solid #dddddd; text-align: right;">{{$t10n($t10aiu)}}</td>
                        </tr>
                    @else
                        <tr>
                            <td style="border-bottom: 0.5px solid #dddddd;">Total (sin imp.)</td>
                            <td style="border-bottom: 0.5px solid #dddddd; text-align: right;">{{$t10n($t10lmt['line_extension_amount'] ?? 0)}}</td>
                        </tr>
                    @endif
                    @if($t10descuento > 0)
                        <tr>
                            <td style="border-bottom: 0.5px solid #dddddd;">Descuentos (-)</td>
                            <td style="border-bottom: 0.5px solid #dddddd; text-align: right;">{{$t10n($t10descuento)}}</td>
                        </tr>
                    @endif
                    @if($t10cargos > 0)
                        <tr>
                            <td style="border-bottom: 0.5px solid #dddddd;">Cargos (+)</td>
                            <td style="border-bottom: 0.5px solid #dddddd; text-align: right;">{{$t10n($t10cargos)}}</td>
                        </tr>
                    @endif
                    @foreach($t10taxes as $t10tax)
                        <tr>
                            <td style="border-bottom: 0.5px solid #dddddd;">
                                {{ optional(\App\Tax::find($t10tax['tax_id'] ?? null))->name ?? 'Impuesto' }}
                                ({{ rtrim(rtrim(number_format((float) ($t10tax['percent'] ?? 0), 2, '.', ''), '0'), '.') }}%)
                            </td>
                            <td style="border-bottom: 0.5px solid #dddddd; text-align: right;">{{$t10n($t10tax['tax_amount'] ?? 0)}}</td>
                        </tr>
                    @endforeach
                    @if($t10ret > 0)
                        <tr>
                            <td style="border-bottom: 0.5px solid #dddddd;">Retenciones (-)</td>
                            <td style="border-bottom: 0.5px solid #dddddd; text-align: right;">{{$t10n($t10ret)}}</td>
                        </tr>
                    @endif
                    <tr>
                        <td style="background-color: #e8e8ea; font-weight: bold;">Total</td>
                        <td style="background-color: #e8e8ea; font-weight: bold; text-align: right;">{{$t10n($t10lmt['payable_amount'] ?? 0)}}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
