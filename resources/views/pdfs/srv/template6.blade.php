<!DOCTYPE html>
<html lang="es">
{{-- <head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
	<title>FACTURA ELECTRONICA Nro: {{$resolution->prefix}} - {{$request->number}}</title>
</head> --}}

<body margin-top:50px>
    @if(isset($request->head_note))
    <div class="row">
        <div class="col-sm-12">
            <table class="table table-bordered table-condensed table-striped table-responsive">
                <thead>
                    <tr>
                        <th class="text-center"><p><strong>{{$request->head_note}}<br/>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
    @endif
    <table style="font-size: 10px">
        <tr>
            <td class="vertical-align-top" style="width: 40%;">
                <table>
                    <tr>
                        <td>CC o NIT:</td>
                        <td>{{$customer->company->identification_number}}-{{$request->customer['dv'] ?? NULL}} </td>
                    </tr>
                    <tr>
                        <td>Cliente:</td>
                        <td>{{$customer->name}}</td>
                    </tr>
                    <tr>
                        <td>Régimen:</td>
                        <td>{{$customer->company->type_regime->name}}</td>
                    </tr>
                    <tr>
                        <td>Obligación:</td>
                        <td>{{$customer->company->type_liability_names ?? $customer->company->type_liability->name}}</td>
                    </tr>
                    <tr>
                        <td>Dirección:</td>
                        <td>{{$customer->company->address}}</td>
                    </tr>
                    <tr>
                        <td>Ciudad:</td>
                        @if($customer->company->country->id == 46)
                            <td>{{$customer->company->municipality->name}} - {{$customer->company->country->name}} </td>
                        @else
                            <td>{{$customer->company->municipality_name}} - {{$customer->company->state_name}} - {{$customer->company->country->name}} </td>
                        @endif
                    </tr>
                    <tr>
                        <td>Teléfono:</td>
                        <td>{{$customer->company->phone}}</td>
                    </tr>
                    <tr>
                        <td>Email:</td>
                        <td>{{$customer->email}}</td>
                    </tr>
                </table>
            </td>
            <td class="vertical-align-top" style="width: 40%; padding-left: 1rem">
                <table>
                    <tr>
                        <td>Forma de Pago:</td>
                        <td>{{$paymentForm[0]->name}}</td>
                    </tr>
                    <tr>
                        <td>Medio de Pago:</td>
                        <td>
                            @foreach ($paymentForm as $paymentF)
                                {{$paymentF->nameMethod}}<br>
                            @endforeach
                        </td>
                    </tr>
                    <tr>
                        <td>Plazo Para Pagar:</td>
                        <td>{{$paymentForm[0]->duration_measure}} Dias</td>
                    </tr>
                    <tr>
                        <td>Fecha Vencimiento:</td>
                        <td>{{$paymentForm[0]->payment_due_date}}</td>
                    </tr>
                    @if(isset($request['order_reference']['id_order']))
                    <tr>
                        <td>Número Pedido:</td>
                        <td>{{$request['order_reference']['id_order']}}</td>
                    </tr>
                    @endif
                    @if(isset($request['order_reference']['issue_date_order']))
                    <tr>
                        <td>Fecha Pedido:</td>
                        <td>{{$request['order_reference']['issue_date_order']}}</td>
                    </tr>
                    @endif
                    @if(isset($healthfields))
                    <tr>
                        <td>Inicio Periodo Facturación:</td>
                        <td>{{$healthfields->invoice_period_start_date}}</td>
                    </tr>
                    <tr>
                        <td>Fin Periodo Facturación:</td>
                        <td>{{$healthfields->invoice_period_end_date}}</td>
                    </tr>
                    @endif
                    @if(isset($request['number_account']))
                    <tr>
                        <td>Número de cuenta:</td>
                        <td>{{$request['number_account'] }}</td>
                    </tr>
                    @endif
                    @if(isset($request['deliveryterms']))
                    <tr>
                        <td>Terminos de Entrega:</td>
                        <td>{{$request['deliveryterms']['loss_risk_responsibility_code']}} - {{ $request['deliveryterms']['loss_risk'] }}</td>
                    </tr>
                    <tr>
                        <td>T.R.M:</td>
                        <td>{{number_format($request['calculationrate'], 2)}}</td>
                    </tr>
                    <tr>
                        <td>Fecha T.R.M:</td>
                        <td>{{$request['calculationratedate']}}</td>
                    </tr>
                    <tr>
                        @inject('currency', 'App\TypeCurrency')
                        <td>Tipo Moneda:</td>
                        <td>{{$currency->findOrFail($request['idcurrency'])['name']}}</td>
                    </tr>
                    @endif
                </table>
            </td>
            <td class="horizontal-align-right" style="width: 20%; text-align: right">
                <img style="width: 150px;" src="{{$imageQr}}">
            </td>
        </tr>
    </table>
        @if(isset($tipodoc) && $tipodoc == 'SRV')
    <h4 style="margin-bottom: 0.5rem;">Información del Servicio</h4>
    <table style="width: 100%; font-size: 12px; margin-bottom: 10px;">
        @if(isset($request['servicio']))
        <tr>
            <td><strong>Nombre del Servicio:</strong></td>
            <td>{{ $request['servicio'] }}</td>
        </tr>
        @endif
        @if(isset($request['fecha_servicio']))
        <tr>
            <td><strong>Fecha de prestación:</strong></td>
            <td>{{ $request['fecha_servicio'] }}</td>
        </tr>
        @endif
        @if(isset($request['responsable']))
        <tr>
            <td><strong>Responsable:</strong></td>
            <td>{{ $request['responsable'] }}</td>
        </tr>
        @endif
        @if(isset($request['observaciones']))
        <tr>
            <td><strong>Observaciones:</strong></td>
            <td>{{ $request['observaciones'] }}</td>
        </tr>
        @endif
        @if(isset($request['order_reference']['id_order']))
        <tr>
            <td><strong>Número de Pedido:</strong></td>
            <td>{{ $request['order_reference']['id_order'] }}</td>
        </tr>
        @endif
        @if(isset($request['order_reference']['issue_date_order']))
        <tr>
            <td><strong>Fecha de Pedido:</strong></td>
            <td>{{ $request['order_reference']['issue_date_order'] }}</td>
        </tr>
        @endif
        @if(isset($request['number_account']))
        <tr>
            <td><strong>Número de Cuenta:</strong></td>
            <td>{{ $request['number_account'] }}</td>
        </tr>
        @endif
        @if(isset($request['sales_assistant']))
        <tr>
            <td><strong>Asesor Comercial:</strong></td>
            <td>{{ $request['sales_assistant'] }}</td>
        </tr>
        @endif
        @if(isset($request['web_site']))
        <tr>
            <td><strong>Sitio Web:</strong></td>
            <td>{{ $request['web_site'] }}</td>
        </tr>
        @endif
        @if(isset($request['dynamic_field']))
        @foreach($request['dynamic_field'] as $field)
        <tr>
            <td><strong>{{ $field['name'] ?? '' }}:</strong></td>
            <td>{{ $field['value'] ?? '' }}</td>
        </tr>
        @endforeach
        @endif
    </table>
@endif
    @if(isset($request['spd']) && is_array($request['spd']))
    <h4 style="margin-bottom: 0.5rem;">Detalle Servicios Públicos Domiciliarios</h4>
    @foreach($request['spd'] as $idx => $spd)
        <table style="width: 100%; font-size: 10px; margin-bottom: 10px; border: 1px solid #ccc;">
            <tr>
                <td colspan="4"><strong>Servicio #{{ $idx + 1 }}</strong></td>
            </tr>
            @if(isset($spd['agency_information']))
                <tr>
                    <td><strong>Oficina de Recaudo:</strong></td>
                    <td>{{ $spd['agency_information']['office_lending_company'] ?? '' }}</td>
                    <td><strong>N° Contrato:</strong></td>
                    <td>{{ $spd['agency_information']['contract_number'] ?? '' }}</td>
                </tr>
                <tr>
                    <td><strong>Fecha Emisión:</strong></td>
                    <td>{{ $spd['agency_information']['issue_date'] ?? '' }}</td>
                    <td><strong>Nota:</strong></td>
                    <td>{{ $spd['agency_information']['note'] ?? '' }}</td>
                </tr>
            @endif
            @if(isset($spd['subscriber_consumption']))
                <tr>
                    <td><strong>Ciclo Facturación:</strong></td>
                    <td>{{ $spd['subscriber_consumption']['duration_of_the_billing_cycle'] ?? '' }}</td>
                    <td><strong>Consumo Totalizado:</strong></td>
                    <td>{{ $spd['subscriber_consumption']['total_metered_quantity'] ?? '' }}</td>
                </tr>
                <tr>
                    <td><strong>Valor Consumo:</strong></td>
                    <td>{{ $spd['subscriber_consumption']['consumption_payable_amount'] ?? '' }}</td>
                    <td><strong>Precio x Cantidad:</strong></td>
                    <td>{{ $spd['subscriber_consumption']['consumption_price_quantity'] ?? '' }}</td>
                </tr>
                @if(isset($spd['subscriber_consumption']['utiliy_meter']))
                    <tr>
                        <td><strong>Medidor:</strong></td>
                        <td>{{ $spd['subscriber_consumption']['utiliy_meter']['meter_number'] ?? '' }}</td>
                        <td><strong>Lectura Anterior:</strong></td>
                        <td>{{ $spd['subscriber_consumption']['utiliy_meter']['previous_meter_reading_date'] ?? '' }} - {{ $spd['subscriber_consumption']['utiliy_meter']['previous_meter_quantity'] ?? '' }}</td>
                    </tr>
                    <tr>
                        <td><strong>Lectura Actual:</strong></td>
                        <td>{{ $spd['subscriber_consumption']['utiliy_meter']['latest_meter_reading_date'] ?? '' }} - {{ $spd['subscriber_consumption']['utiliy_meter']['latest_meter_quantity'] ?? '' }}</td>
                        <td><strong>Método Lectura:</strong></td>
                        <td>{{ $spd['subscriber_consumption']['utiliy_meter']['meter_reading_method'] ?? '' }}</td>
                    </tr>
                @endif
                @if(isset($spd['subscriber_consumption']['payment_agreements']))
                    <tr>
                        <td colspan="4"><strong>Acuerdos de Pago:</strong></td>
                    </tr>
                    @foreach($spd['subscriber_consumption']['payment_agreements'] as $acuerdo)
                        <tr>
                            <td>Contrato: {{ $acuerdo['contract_number'] ?? '' }}</td>
                            <td>Servicio: {{ $acuerdo['good_service_name'] ?? '' }}</td>
                            <td>Descripción: {{ $acuerdo['description'] ?? '' }}</td>
                            <td>Valor Cuota: {{ $acuerdo['fee_value_to_pay'] ?? '' }}</td>
                        </tr>
                    @endforeach
                @endif
            @endif
        </table>
    @endforeach
@endif
    @isset($healthfields)
        <table class="table" style="width: 100%;">
            <thead>
                <tr>
                    <th class="text-center" style="width: 100%;">INFORMACION REFERENCIAL SECTOR SALUD</th>
                </tr>
            </thead>
        </table>
        <table class="table" style="width: 100%;">
            <thead>
                <tr>
                    <th class="text-center" style="width: 12%;">Cod Prestador</th>
                    <th class="text-center" style="width: 25%;">Datos Usuario</th>
                    <th class="text-center" style="width: 25%;">Info. Contrat./Cobertura</th>
                    <th class="text-center" style="width: 20%;">Nros. Autoriz./MIPRES</th>
                    <th class="text-center" style="width: 18%;">Info. de Pagos</th>
                </tr>
            </thead>
            <tbody>
                @foreach($healthfields->users_info as $item)
                    <tr>
                        <td>
                            <p style="font-size: 8px">{{$item->provider_code}}</p>
                        </td>
                        <td>
                            <p style="font-size: 8px">Modalidad Contratación: {{$item->health_contracting_payment_method()->name}}</p>
                            <p style="font-size: 8px">Nro. Contrato: {{$item->contract_number}}</p>
                            <p style="font-size: 8px">Cobertura: {{$item->health_coverage()->name}}</p>
                        </td>
                        <td>
                            <p style="font-size: 8px">Copago: {{number_format($item->co_payment, 2)}}</p>
                            <p style="font-size: 8px">Cuota Moderardora: {{number_format($item->moderating_fee, 2)}}</p>
                            <p style="font-size: 8px">Pagos Compartidos: {{number_format($item->shared_payment, 2)}}</p>
                            <p style="font-size: 8px">Anticipos: {{number_format($item->advance_payment, 2)}}</p>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <br>
    @endisset
    <table class="table" style="width: 100%;font-size: 8px">
        <thead>
            <tr>
                <th class="text-center">#</th>
                <th class="text-center">Código</th>
                <th class="text-center">Descripción</th>
                <th class="text-center">Cant</th>
                <th class="text-center">Val. Unit</th>
                <th class="text-center">IVA/IC</th>
                <th class="text-center">Dcto</th>
                <th class="text-center">Val. Item</th>
            </tr>
        </thead>
        <tbody>
            <?php $ItemNro = 0; $TotalDescuentosEnLineas = 0; ?>
            @foreach($request['invoice_lines'] as $item)
                <?php $ItemNro = $ItemNro + 1; ?>
                <tr>
                    @inject('um', 'App\UnitMeasure')
                    @if($item['description'] == 'Administración' or $item['description'] == 'Imprevisto' or $item['description'] == 'Utilidad')
                        <td>{{$ItemNro}}</td>
                        <td class="text-right">
                            {{$item['code']}}
                        </td>
                        <td>{{$item['description']}}</td>
                        <td class="text-right"></td>
                        <td class="text-right"></td>
                        <td class="text-right">{{number_format($item['price_amount'], 2)}}</td>
                        <td class="text-right">{{number_format($item['tax_totals'][0]['tax_amount'], 2)}}</td>
                        @if(isset($item['allowance_charges']))
                            <?php $TotalDescuentosEnLineas = $TotalDescuentosEnLineas + $item['allowance_charges'][0]['amount'] ?>
                            <td class="text-right">{{number_format($item['allowance_charges'][0]['amount'], 2)}}</td>
                        @else
                            <td class="text-right">{{number_format("0", 2)}}</td>
                        @endif
                        <td class="text-right">{{number_format($item['invoiced_quantity'] * $item['price_amount'], 2)}}</td>
                    @else
                        <td><p style="font-size: 8px">{{$ItemNro}}</p></td>
                        <td><p style="font-size: 8px">{{$item['code']}}</p></td>
                        <td>
                            @if(isset($item['notes']))
                            <p style="font-size: 8px">{{$item['description']}}</p>
                                <p style="font-style: italic; font-size: 6px"><strong>Nota: {{$item['notes']}}</strong></p>
                            @else
                                <p style="font-size: 8px">{{$item['description']}}</p>
                            @endif
                        </td>
                        <td class="text-right"><p style="font-size: 8px">{{number_format($item['invoiced_quantity'], 2)}}</p></td>

                        @if(isset($item['tax_totals']))
                            @if(isset($item['allowance_charges']))
                                <td class="text-right"><p style="font-size: 8px">{{number_format(($item['line_extension_amount'] + $item['allowance_charges'][0]['amount']) / $item['invoiced_quantity'], 2)}}</p></td>
                            @else
                                <td class="text-right"><p style="font-size: 8px">{{number_format($item['line_extension_amount'] / $item['invoiced_quantity'], 2)}}</p></td>
                            @endif
                        @else
                            @if(isset($item['allowance_charges']))
                                <td class="text-right"><p style="font-size: 8px">{{number_format(($item['line_extension_amount'] + $item['allowance_charges'][0]['amount']) / $item['invoiced_quantity'], 2)}}</p></td>
                            @else
                                <td class="text-right"><p style="font-size: 8px">{{number_format($item['line_extension_amount'] / $item['invoiced_quantity'], 2)}}</p></td>
                            @endif
                        @endif

                        @if(isset($item['tax_totals']))
                            @if(isset($item['tax_totals'][0]['tax_amount']))
                                <td class="text-right"><p style="font-size: 8px">{{number_format($item['tax_totals'][0]['tax_amount'] / $item['invoiced_quantity'], 2)}}</p></td>
                            @else
                                <td class="text-right"><p style="font-size: 8px">{{number_format(0, 2)}}</p></td>
                            @endif
                        @else
                            <td class="text-right"><p style="font-size: 8px">E</p></td>
                        @endif

                        @if(isset($item['allowance_charges']))
                            <?php $TotalDescuentosEnLineas = $TotalDescuentosEnLineas + ($item['allowance_charges'][0]['amount'] / $item['invoiced_quantity']) ?>
                            <td class="text-right"><p style="font-size: 8px">{{number_format($item['allowance_charges'][0]['amount'] / $item['invoiced_quantity'], 2)}}</p></td>
                            @if(isset($item['tax_totals']))
                                <td class="text-right"><p style="font-size: 8px">{{number_format(($item['line_extension_amount'] + ($item['tax_totals'][0]['tax_amount'])), 2)}}</p></td>
                            @else
                                <td class="text-right"><p style="font-size: 8px">{{number_format(($item['line_extension_amount']), 2)}}</p></td>
                            @endif
                        @else
                            <td class="text-right"><p style="font-size: 8px">{{number_format("0", 2)}}</p></td>
                            <td class="text-right"><p style="font-size: 8px">{{number_format($item['invoiced_quantity'] * ($item['line_extension_amount'] / $item['invoiced_quantity']), 2)}}</p></td>
                        @endif
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <br>

    <table class="table" style="width: 100%">
        <thead>
            <tr>
                <th class="text-center">Impuestos</th>
                <th class="text-center">Retenciones</th>
                <th class="text-center">Totales</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="width: 40%;">
                    <table class="table" style="width: 100%">
                        <thead>
                            <tr>
                                <th class="text-center">Tipo</th>
                                <th class="text-center">Base</th>
                                <th class="text-center">Porcentaje</th>
                                <th class="text-center">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @if(isset($request->tax_totals))
                                <?php $TotalImpuestos = 0; ?>
                                @foreach($request->tax_totals as $item)
                                    <tr>
                                        <?php $TotalImpuestos = $TotalImpuestos + $item['tax_amount'] ?>
                                        @inject('tax', 'App\Tax')
                                        <td>{{$tax->findOrFail($item['tax_id'])['name']}}</td>
                                        <td class="text-right">{{number_format($item['taxable_amount'], 2)}}</td>
                                        <td class="text-right">{{number_format($item['percent'], 2)}}%</td>
                                        <td class="text-right">{{number_format($item['tax_amount'], 2)}}</td>
                                    </tr>
                                @endforeach
                            @else
                                <?php $TotalImpuestos = 0; ?>
                            @endif
                        </tbody>
                    </table>
                </td>
                <td style="width: 30%;">
                    <table class="table" style="width: 100%">
                        <thead>
                            <tr>
                                <th class="text-center">Tipo</th>
                                <th class="text-center">Base</th>
                                <th class="text-center">Porcentaje</th>
                                <th class="text-center">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @if(isset($withHoldingTaxTotal))
                                <?php $TotalRetenciones = 0; ?>
                                @foreach($withHoldingTaxTotal as $item)
                                    <tr>
                                        <?php $TotalRetenciones = $TotalRetenciones + $item['tax_amount'] ?>
                                        @inject('tax', 'App\Tax')
                                        <td>{{$tax->findOrFail($item['tax_id'])['name']}}</td>
                                        <td class="text-right">{{number_format($item['taxable_amount'], 2)}}</td>
                                        <td class="text-right">{{number_format($item['percent'], 2)}}%</td>
                                        <td class="text-right">{{number_format($item['tax_amount'], 2)}}</td>
                                    </tr>
                                @endforeach
                            @endif
                        </tbody>
                    </table>
                </td>
                <td style="width: 30%;">
                    <table class="table" style="width: 100%">
                        <thead>
                            <tr>
                                <th class="text-center">Concepto</th>
                                <th class="text-center">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Nro Lineas:</td>
                                <td class="text-right">{{$ItemNro}}</td>
                            </tr>
                            <tr>
                                <td>Base:</td>
                                <td class="text-right">{{number_format($request->legal_monetary_totals['line_extension_amount'], 2)}}</td>
                            </tr>
                            <tr>
                                <td>Impuestos:</td>
                                <td class="text-right">{{number_format($TotalImpuestos, 2)}}</td>
                            </tr>
                            <tr>
                                <td>Retenciones:</td>
                                <td class="text-right">{{number_format($TotalRetenciones, 2)}}</td>
                            </tr>
                            <tr>
                                <td>Descuentos En Lineas:</td>
                                <td class="text-right">{{number_format($TotalDescuentosEnLineas, 2)}}</td>
                            </tr>
                            <tr>
                                <td>Descuentos Globales:</td>
                                @if(isset($request->legal_monetary_totals['allowance_total_amount']))
                                    <td class="text-right">{{number_format($request->legal_monetary_totals['allowance_total_amount'], 2)}}</td>
                                @else
                                    <td class="text-right">{{number_format(0, 2)}}</td>
                                @endif
                            </tr>
                            @if(isset($request->previous_balance))
                                @if($request->previous_balance > 0)
                                    <tr>
                                        <td>Saldo Anterior:</td>
                                        <td class="text-right">{{number_format($request->previous_balance, 2)}}</td>
                                    </tr>
                                @endif
                            @endif
                            <tr>
                                <td>Total Factura - Descuentos:</td>
                                @if(isset($request->tarifaica))
{{--                                    @if(isset($request->legal_monetary_totals['allowance_total_amount']))
                                        @if(isset($request->previous_balance))
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + $request->legal_monetary_totals['allowance_total_amount'] + $request->previous_balance, 2)}}</td>
                                        @else
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + $request->legal_monetary_totals['allowance_total_amount'], 2)}}</td>
                                        @endif
                                    @else       --}}
                                        @if(isset($request->previous_balance))
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + 0 + $request->previous_balance, 2)}}</td>
                                        @else
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + 0, 2)}}</td>
                                        @endif
{{--                                    @endif  --}}
                                @else
                                    @if(isset($request->previous_balance))
                                        <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + $request->previous_balance, 2)}}</td>
                                    @else
                                        <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'], 2)}}</td>
                                    @endif
                                @endif
                            </tr>
                            <tr>
                                <td>Total Factura:</td>
                                @if(isset($request->tarifaica))
{{--                                    @if(isset($request->legal_monetary_totals['allowance_total_amount']))
                                        @if(isset($request->previous_balance))
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + $request->legal_monetary_totals['allowance_total_amount'] + $request->previous_balance, 2)}}</td>
                                        @else
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + $request->legal_monetary_totals['allowance_total_amount'], 2)}}</td>
                                        @endif
                                    @else   --}}
                                        @if(isset($request->previous_balance))
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + 0 + $request->previous_balance, 2)}}</td>
                                        @else
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + 0, 2)}}</td>
                                        @endif
{{--                                    @endif  --}}
                                @else
{{--                                    @if(isset($request->legal_monetary_totals['allowance_total_amount']))
                                        @if(isset($request->previous_balance))
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + $request->legal_monetary_totals['allowance_total_amount'] + $request->previous_balance, 2)}}</td>
                                        @else
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + $request->legal_monetary_totals['allowance_total_amount'], 2)}}</td>
                                        @endif
                                    @else   --}}
                                        @if(isset($request->previous_balance))
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'] + $request->previous_balance, 2)}}</td>
                                        @else
                                            <td class="text-right">{{number_format($request->legal_monetary_totals['payable_amount'], 2)}}</td>
                                        @endif
{{--                                    @endif  --}}
                                @endif
                            </tr>
                        </tbody>
                    </table>
                </td>
            </tr>
        </tbody>
    </table>
    <br>
    <div class="summarys">
        <div class="text-word" id="note">
            @inject('Varios', 'App\Custom\NumberSpellOut')
            <p><strong>NOTAS:</strong></p>
            <p style="font-style: italic; font-size: 5px">{{$notes}}</p>
            <br>
            @if(isset($request->tarifaica))
{{--                @if(isset($request->legal_monetary_totals['allowance_total_amount']))
                    @if(isset($request->previous_balance))
                        <p> <strong>SON</strong>: {{$Varios->convertir(round($request->legal_monetary_totals['payable_amount'] + $request->legal_monetary_totals['allowance_total_amount'] + $request->previous_balance, $request->idcurrency, 2))}} M/CTE*********.</p>
                    @else
                        <p> <strong>SON</strong>: {{$Varios->convertir(round($request->legal_monetary_totals['payable_amount'] + $request->legal_monetary_totals['allowance_total_amount'], $request->idcurrency, 2))}} M/CTE*********.</p>
                    @endif
                @else   --}}
                    @if(isset($request->previous_balance))
                        <p> <strong>SON</strong>: {{$Varios->convertir(round($request->legal_monetary_totals['payable_amount'] + 0 + $request->previous_balance, 2))}} M/CTE*********.</p>
                    @else
                        <p> <strong>SON</strong>: {{$Varios->convertir(round($request->legal_monetary_totals['payable_amount'] + 0, 2))}} M/CTE*********.</p>
                    @endif
{{--                @endif  --}}
            @else
                @if(isset($request->previous_balance))
                    <p style="font-style: italic; font-size: 5px"><strong>SON</strong>: {{$Varios->convertir(round($request->legal_monetary_totals['payable_amount'] + $request->previous_balance, 2), $request->idcurrency)}} M/CTE*********.</p>
                @else
                    <p style="font-style: italic; font-size: 5px"><strong>SON</strong>: {{$Varios->convertir(round($request->legal_monetary_totals['payable_amount'], 2), $request->idcurrency)}} M/CTE*********.</p>
                @endif
            @endif
        </div>
    </div>
@if(
    (isset($request->disable_confirmation_text) && !$request->disable_confirmation_text)
    || (isset($firma_facturacion) && !is_null($firma_facturacion))
)
    <div class="summary" >
        <div class="text-word" id="note">
            @if(isset($request->disable_confirmation_text))
                @if(!$request->disable_confirmation_text)
                    <p style="font-style: italic;">INFORME EL PAGO AL TELEFONO {{$company->phone}} o al e-mail {{$user->email}}<br>
                        {{-- <br>
                        <div id="firma">
                            <p><strong>FIRMA ACEPTACIÓN:</strong></p><br>
                            <p><strong>CC:</strong></p><br>
                            <p><strong>FECHA:</strong></p><br>
                        </div> --}}
                    </p>
                @endif
            @endif
        </div>
        @if(isset($firma_facturacion) and !is_null($firma_facturacion))
            <table style="font-size: 10px">
                <tr>
                    <td class="vertical-align-top" style="width: 50%; text-align: right">
                        <img style="width: 250px;" src="{{$firma_facturacion}}">
                    </td>
                </tr>
            </table>
        @endif
    </div>
@endif
</body>
</html>
