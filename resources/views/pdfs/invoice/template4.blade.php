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
            <td class="vertical-align-top" style="width: 30%;">
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
                    @if(isset($request->customer['collection_route']))
                    <tr>
                        <td>Ruta de Cobro:</td>
                        <td>{{$request->customer['collection_route']}}</td>
                    </tr>
                    @endif
                </table>
            </td>
            <td class="vertical-align-top" style="width: 40%; padding-left: 1rem">
                <table>
                    @if(!empty($paymentForm) && count($paymentForm) > 0)
                    <tr>
                        <td>Forma de Pago:</td>
                        <td>{{$paymentForm[0]->name}}</td>
                    </tr>
                    <tr>
                        <td>Medios de Pago:</td>
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
                    @endif
                    @if(isset($request['seller']) && isset($request['seller']['name']))
                    <tr>
                        <td>Vendedor:</td>
                        <td>{{$request['seller']['name']}}</td>
                    </tr>
                    @endif
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
                    @if(isset($request['deliveryterms']) && ($request['deliveryterms'] != []))
                    <tr>
                        <td>Terminos de Entrega:</td>
                        <td>{{$request['deliveryterms']['loss_risk_responsibility_code']}} - {{ $request['deliveryterms']['loss_risk'] }}</td>
                    </tr>
                    <tr>
                        <td>T.R.M:</td>
                        <td>{{number_format($request['k_supplement']['FctConvCop'], 2)}}</td>
                    </tr>
                    <tr>
                        <td>Destino</td>
                        <td>{{$request['k_supplement']['destination']}}</td>
                    </tr>
                    <tr>
                        @inject('currency', 'App\TypeCurrency')
                        <td>Tipo Moneda:</td>
                        <td>{{$currency->where('code', 'like', '%'.$request['k_supplement']['MonedaCop'].'%')->firstOrFail()['name']}}</td>
                    </tr>
                    @endif
                </table>
            </td>
            <td class="vertical-align-top" style="width: 30%; text-align: right">
                <img style="width: 150px;" src="{{$imageQr}}">
            </td>
        </tr>
    </table>
    <br>
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
    <?php
        $showPurchaseOrderColumn = false;
        foreach($request['invoice_lines'] as $line) {
            if(isset($line['purchase_order_number']) && $line['purchase_order_number']) {
                $showPurchaseOrderColumn = true;
                break;
            }
        }
    ?>
    <table class="table" style="width: 100%;">
        <thead>
            <tr>
                <th class="text-center">#</th>
                <th class="text-center">Código</th>
                <th class="text-center">Descripción</th>
                @if($showPurchaseOrderColumn)
                    <th class="text-center">Orden de compra</th>
                @endif
                <th class="text-center">Cantidad</th>
                <th class="text-center">UM</th>
                <th class="text-center">Val. Unit</th>
                <th class="text-center">IVA/IC</th>
                <th class="text-center">%</th>
                <th class="text-center">Dcto</th>
                <th class="text-center">%</th>
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
                        <td class="text-right">{{number_format($item['tax_totals'][0]['percent'], 2)}}</td>
                        @if(isset($item['allowance_charges']))
                            <?php $TotalDescuentosEnLineas = $TotalDescuentosEnLineas + ($item['allowance_charges'][0]['amount']) ?>
                            <td class="text-right">{{number_format($item['allowance_charges'][0]['amount'], 2)}}</td>
                            <td class="text-right">{{number_format(($item['allowance_charges'][0]['amount'] * 100) / $item['allowance_charges'][0]['base_amount'], 2)}}</td>
                        @else
                            <td class="text-right">{{number_format("0", 2)}}</td>
                            <td class="text-right">{{number_format("0", 2)}}</td>
                        @endif
                        <td class="text-right">{{number_format($item['invoiced_quantity'] * $item['price_amount'], 2)}}</td>
                    @else
                        <td>{{$ItemNro}}</td>
                        <td>{{$item['code']}}</td>
                        <td>
                            @if(isset($item['notes']))
                                {{$item['description']}}
                                <p style="font-style: italic; font-size: 7px"><strong>Nota: {{$item['notes']}}</strong></p>
                            @else
                                {{$item['description']}}
                            @endif
                            @if(!empty($item['lot_code']) || !empty($item['date_of_due']))
                                <p style="font-size: 7px; margin: 0; font-style: italic;">
                                    @if(!empty($item['lot_code']))<strong>Lote:</strong> {{$item['lot_code']}}@endif
                                    @if(!empty($item['lot_code']) && !empty($item['date_of_due'])) | @endif
                                    @if(!empty($item['date_of_due']))<strong>Vence:</strong> {{$item['date_of_due']}}@endif
                                </p>
                            @endif
                            @php $itemAttrs = array_filter(is_array($item['item_attributes'] ?? null) ? $item['item_attributes'] : [], function ($a) { return !empty($a['value']); }); @endphp
                            @if(count($itemAttrs))
                                <p style="font-size: 7px; margin: 0; font-style: italic;">
                                    @foreach($itemAttrs as $attr)<strong>{{$attr['description'] ?? ''}}:</strong> {{$attr['value']}}@if(!$loop->last) | @endif @endforeach
                                </p>
                            @endif
                        </td>
                        @if($showPurchaseOrderColumn)
                            <td>
                                @if(isset($item['purchase_order_number']) && $item['purchase_order_number'])
                                    {{$item['purchase_order_number']}}
                                @endif
                            </td>
                        @endif
                        <td class="text-right">{{number_format($item['invoiced_quantity'], 2)}}</td>
                        <td class="text-right">{{$um->findOrFail($item['unit_measure_id'])['name']}}</td>

                        @if(isset($item['tax_totals']))
                            @if(isset($item['allowance_charges']))
                                <td class="text-right">{{number_format(($item['line_extension_amount'] + $item['allowance_charges'][0]['amount']) / $item['invoiced_quantity'], 2)}}</td>
                            @else
                                <td class="text-right">{{number_format($item['line_extension_amount'] / $item['invoiced_quantity'], 2)}}</td>
                            @endif
                        @else
                            @if(isset($item['allowance_charges']))
                                <td class="text-right">{{number_format(($item['line_extension_amount'] + $item['allowance_charges'][0]['amount']) / $item['invoiced_quantity'], 2)}}</td>
                            @else
                                <td class="text-right">{{number_format($item['line_extension_amount'] / $item['invoiced_quantity'], 2)}}</td>
                            @endif
                        @endif

                        @if(isset($item['tax_totals']))
                            @if(isset($item['tax_totals'][0]['tax_amount']))
                                <td class="text-right">{{number_format($item['tax_totals'][0]['tax_amount'], 2)}}</td>
                                <td class="text-right">{{number_format($item['tax_totals'][0]['percent'], 2)}}</td>
                            @else
                                <td class="text-right">{{number_format(0, 2)}}</td>
                                <td class="text-right">{{number_format(0, 2)}}</td>
                            @endif
                        @else
                            <td class="text-right">E</td>
                        @endif

                        @if(isset($item['allowance_charges']))
                            <?php $TotalDescuentosEnLineas = $TotalDescuentosEnLineas + ($item['allowance_charges'][0]['amount']) ?>
                            <td class="text-right">{{number_format($item['allowance_charges'][0]['amount'] / $item['invoiced_quantity'], 2)}}</td>
                            <td class="text-right">{{number_format(($item['allowance_charges'][0]['amount'] * 100) / $item['allowance_charges'][0]['base_amount'], 2)}}</td>
                            <td class="text-right">{{number_format($item['invoiced_quantity'] * $item['price_amount'] - $item['allowance_charges'][0]['amount'], 2)}}</td>
                        @else
                            <td class="text-right">{{number_format("0", 2)}}</td>
                            <td class="text-right">{{number_format("0", 2)}}</td>
                            <td class="text-right">{{number_format($item['invoiced_quantity'] * $item['price_amount'], 2)}}</td>
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
                                        <td>{{ isset($item['tax_name']) && $item['tax_name'] ? $item['tax_name'] : $tax->findOrFail($item['tax_id'])['name'] }}</td>
                                        <td class="text-right">{{number_format($item['taxable_amount'], 2)}}</td>
                                        <td class="text-right">
                                            @if(isset($item['percent']))
                                                {{ number_format($item['percent'], 2) }}%
                                            @elseif(isset($item['per_unit_amount']) && isset($item['base_unit_measure']))
                                                {{ number_format($item['per_unit_amount'], 2) }} x {{ number_format($item['base_unit_measure'], 2) }}
                                            @else
                                                -
                                            @endif
                                        </td>
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
                                        <td>{{ isset($item['tax_name']) && $item['tax_name'] ? $item['tax_name'] : $tax->findOrFail($item['tax_id'])['name'] }}</td>
                                        <td class="text-right">{{number_format($item['taxable_amount'], 2)}}</td>
                                        <td class="text-right">
                                            @if(isset($item['percent']))
                                                {{ number_format($item['percent'], 2) }}%
                                            @elseif(isset($item['per_unit_amount']) && isset($item['base_unit_measure']))
                                                {{ number_format($item['per_unit_amount'], 2) }} x {{ number_format($item['base_unit_measure'], 2) }}
                                            @else
                                                -
                                            @endif
                                        </td>
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
                            @php
                                $aiuBase = 0;
                                foreach ($request->invoice_lines as $aiuLine) {
                                    if (isset($aiuLine['code']) && in_array($aiuLine['code'], ['ADMINIST', 'IMPREVIS', 'UTILIDAD']))
                                        $aiuBase += (float) $aiuLine['line_extension_amount'];
                                }
                            @endphp
                            @if($aiuBase > 0)
                                <tr>
                                    <td>Base servicio:</td>
                                    <td class="text-right">{{number_format($request->legal_monetary_totals['line_extension_amount'] - $aiuBase, 2)}}</td>
                                </tr>
                                <tr>
                                    <td>Base AIU:</td>
                                    <td class="text-right">{{number_format($aiuBase, 2)}}</td>
                                </tr>
                            @else
                                <tr>
                                    <td>Base:</td>
                                    <td class="text-right">{{number_format($request->legal_monetary_totals['line_extension_amount'], 2)}}</td>
                                </tr>
                            @endif
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
                            @if(isset($request->legal_monetary_totals['charge_total_amount']))
                                @if($request->legal_monetary_totals['charge_total_amount'] > 0)
                                    <?php $charge_number = 0; ?>
                                    @foreach($request['allowance_charges'] as $allowance_charge)
                                        @if(isset($allowance_charge))
                                            @if($allowance_charge['charge_indicator'] == true)
                                                <?php $charge_number++; ?>
                                                <tr>
                                                    <td>{{$allowance_charge['allowance_charge_reason'] ?? "Cargo Global Nro: ".$charge_number}}</td>
                                                    <td class="text-right">{{number_format($allowance_charge['amount'], 2)}}</td>
                                                </tr>
                                            @endif
                                        @endif
                                    @endforeach
                                @endif
                            @endif
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
    @php
        $pagosAplicados = [];
        if (!empty($request['payments'])) {
            foreach ($request['payments'] as $pago) {
                $pagosAplicados[] = [
                    'medio' => !empty($pago['payment_method_name']) ? $pago['payment_method_name'] : 'Anticipo',
                    'fecha' => $pago['paiddate'] ?? '',
                    'valor' => $pago['paidamount'] ?? 0,
                ];
            }
        } elseif (!empty($request['prepaid_payments']) || !empty($request['prepaid_payment'])) {
            $anticipos = !empty($request['prepaid_payments']) ? $request['prepaid_payments'] : [$request['prepaid_payment']];
            foreach ($anticipos as $pago) {
                $pagosAplicados[] = [
                    'medio' => !empty($pago['payment_method_name']) ? $pago['payment_method_name'] : 'Anticipo',
                    'fecha' => $pago['paiddate'] ?? '',
                    'valor' => $pago['paidamount'] ?? 0,
                ];
            }
        }
    @endphp
    @if(count($pagosAplicados) > 0)
        @php
            $totalPagado = 0;
            foreach ($pagosAplicados as $pago) {
                $totalPagado += $pago['valor'];
            }
            // La fila "Total Factura" de esta plantilla no resta retenciones; el saldo replica ese mismo total
            $saldoPendiente = ($request->legal_monetary_totals['payable_amount'] + ($request->previous_balance ?? 0)) - $totalPagado;
        @endphp
        <table class="table" style="width: 100%; font-size: 10px; border: 1px solid #ccc; margin-top: 10px; page-break-inside: avoid;">
            <thead>
                <tr>
                    <th colspan="4" class="text-center" style="background: #f3f3f3;"><strong>Pagos Aplicados</strong></th>
                </tr>
                <tr>
                    <th class="text-center">#</th>
                    <th class="text-center">Medio de Pago</th>
                    <th class="text-center">Fecha de Pago</th>
                    <th class="text-center">Valor</th>
                </tr>
            </thead>
            <tbody>
                @foreach($pagosAplicados as $index => $pago)
                    <tr>
                        <td class="text-center">{{ $index + 1 }}</td>
                        <td>{{ $pago['medio'] }}</td>
                        <td class="text-center">{{ $pago['fecha'] }}</td>
                        <td class="text-right">{{ number_format($pago['valor'], 2) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="3"><strong>Total Pagado</strong></td>
                    <td class="text-right"><strong>{{ number_format($totalPagado, 2) }}</strong></td>
                </tr>
                <tr>
                    <td colspan="3"><strong>Saldo Pendiente por Pagar</strong></td>
                    <td class="text-right"><strong>{{ number_format($saldoPendiente, 2) }}</strong></td>
                </tr>
            </tbody>
        </table>
    @endif
    @if(!empty($request['payment_plan']) && !empty($request['payment_plan']['installments_count']))
        <?php $paymentPlan = $request['payment_plan']; ?>
        <?php
            $frequencyLabels = [
                'daily'   => 'Diario',
                'weekly'  => 'Semanal',
                'biweekly'=> 'Quincenal',
                'monthly' => 'Mensual',
            ];
        ?>
        <table class="table" style="width: 100%; font-size: 10px; border: 1px solid #ccc; margin-top: 10px; page-break-inside: avoid;">
            <thead>
                <tr>
                    <th colspan="5" class="text-center" style="background: #f3f3f3;"><strong>Plan de Pagos</strong></th>
                </tr>
                <tr>
                    <th class="text-center">Frecuencia</th>
                    <th class="text-center">Nro. Cuotas</th>
                    <th class="text-center">Valor Cuota</th>
                    <th class="text-center">Fecha Inicio</th>
                    <th class="text-center">Monto Total</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-center">{{ isset($paymentPlan['frequency']) ? ($frequencyLabels[$paymentPlan['frequency']] ?? $paymentPlan['frequency']) : '' }}</td>
                    <td class="text-center">{{ $paymentPlan['installments_count'] ?? '' }}</td>
                    <td class="text-right">{{ isset($paymentPlan['installment_amount']) ? number_format($paymentPlan['installment_amount'], 2) : '' }}</td>
                    <td class="text-center">{{ $paymentPlan['start_date'] ?? '' }}</td>
                    <td class="text-right">{{ isset($paymentPlan['total_amount']) ? number_format($paymentPlan['total_amount'], 2) : '' }}</td>
                </tr>
            </tbody>
        </table>
        @if(isset($paymentPlan['installments']) && count($paymentPlan['installments']) > 0)
            <table class="table" style="width: 100%; font-size: 11px; border: 1px solid #ccc;">
                <thead>
                    <tr>
                        <th class="text-center">Cuota</th>
                        <th class="text-center">Fecha Vencimiento</th>
                        <th class="text-center">Valor Esperado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($paymentPlan['installments'] as $installment)
                        <tr>
                            <td class="text-center">{{ $installment['number'] }}</td>
                            <td class="text-center">{{ $installment['due_date'] }}</td>
                            <td class="text-right">{{ number_format($installment['expected_amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endif
    <div class="summarys">
        <div class="text-word" id="note">
            @inject('Varios', 'App\Custom\NumberSpellOut')
            <p><strong>NOTAS:</strong></p>
            <p style="font-style: italic; font-size: 9px">{{$notes}}</p>
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
                    <p><strong>SON</strong>: {{$Varios->convertir(round($request->legal_monetary_totals['payable_amount'] + $request->previous_balance, 2), $request->idcurrency)}} M/CTE*********.</p>
                @else
                    <p><strong>SON</strong>: {{$Varios->convertir(round($request->legal_monetary_totals['payable_amount'], 2), $request->idcurrency)}} M/CTE*********.</p>
                @endif
            @endif
        </div>
    </div>

    @php
        $showConfirmationText = isset($request->disable_confirmation_text) && !$request->disable_confirmation_text;
        $showFirma = isset($firma_facturacion) && !is_null($firma_facturacion);
    @endphp
    @if($showConfirmationText || $showFirma)
        <div class="summary" >
            <div class="text-word" id="note">
                @if($showConfirmationText)
                    <p style="font-style: italic;">INFORME EL PAGO AL TELEFONO {{$company->phone}} o al e-mail {{$user->email}}<br>
                        {{-- <br>
                        <div id="firma">
                            <p><strong>FIRMA ACEPTACIÓN:</strong></p><br>
                            <p><strong>CC:</strong></p><br>
                            <p><strong>FECHA:</strong></p><br>
                        </div> --}}
                    </p>
                @endif
            </div>
            @if($showFirma)
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
