@if(!empty($request->valor_en_letras))
    @inject('VariosGrafica', 'App\Custom\NumberSpellOut')
    @php
        $totalesLetras = $request->legal_monetary_totals ?? ($request->requested_monetary_totals ?? []);
        $montoLetras = (float) ($totalesLetras['payable_amount'] ?? 0);
        if (isset($request->previous_balance)) {
            $montoLetras += (float) $request->previous_balance;
        }
        if (isset($TotalRetenciones)) {
            $montoLetras -= (float) $TotalRetenciones;
        } elseif (isset($withHoldingTaxTotal)) {
            $montoLetras -= collect($withHoldingTaxTotal)->sum('tax_amount');
        }
    @endphp
    <p style="font-size: 10px; margin-top: 8px;"><strong>SON</strong>: {{ $VariosGrafica->convertir(round($montoLetras, 2), $request->idcurrency ?? null) }} M/CTE.</p>
@endif
