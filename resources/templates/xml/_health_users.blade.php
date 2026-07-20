@php
    $healthUsersList = collect($healthUsers ?? []);
    $healthUser = $healthUsersList->first();
    $totalCoPayment = $healthUsersList->sum(fn ($u) => floatval($u->co_payment));
    $totalModeratingFee = $healthUsersList->sum(fn ($u) => floatval($u->moderating_fee));
    $totalSharedPayment = $healthUsersList->sum(fn ($u) => floatval($u->shared_payment));
    $totalAdvancePayment = $healthUsersList->sum(fn ($u) => floatval($u->advance_payment));
@endphp
@if($healthUser)
    <Collection schemeName="Usuario">
        <AdditionalInformation>
            <Name>CODIGO_PRESTADOR</Name>
            <Value>{{preg_replace("/[\r\n|\n|\r]+/", "", $healthUser->provider_code)}}</Value>
        </AdditionalInformation>
        <AdditionalInformation>
            <Name>MODALIDAD_PAGO</Name>
            <Value schemeName="salud_modalidad_pago.gc" schemeID="{{preg_replace("/[\r\n|\n|\r]+/", "", $healthUser->health_contracting_payment_method()->code)}}">{{preg_replace("/[\r\n|\n|\r]+/", "", $healthUser->health_contracting_payment_method()->name)}}</Value>
        </AdditionalInformation>
        <AdditionalInformation>
            <Name>COBERTURA_PLAN_BENEFICIOS</Name>
            <Value schemeName="salud_cobertura.gc" schemeID="{{preg_replace("/[\r\n|\n|\r]+/", "", $healthUser->health_coverage()->code)}}">{{preg_replace("/[\r\n|\n|\r]+/", "", $healthUser->health_coverage()->name)}}</Value>
        </AdditionalInformation>
        <AdditionalInformation>
            <Name>NUMERO_CONTRATO</Name>
            <Value>{{preg_replace("/[\r\n|\n|\r]+/", "", $healthUser->contract_number)}}</Value>
        </AdditionalInformation>
        <AdditionalInformation>
            <Name>NUMERO_POLIZA</Name>
            <Value>{{preg_replace("/[\r\n|\n|\r]+/", "", $healthUser->policy_number)}}</Value>
        </AdditionalInformation>
        @if(empty($healthUser->contract_number) && !empty($healthUser->health_invoice_without_contract_id))
        <AdditionalInformation>
            <Name>FACTURA_SIN_CONTRATO</Name>
            <Value schemeName="salud_cobertura.gc" schemeID="{{preg_replace("/[\r\n|\n|\r]+/", "", $healthUser->health_invoice_without_contract()->code)}}">{{preg_replace("/[\r\n|\n|\r]+/", "", $healthUser->health_invoice_without_contract()->name)}}</Value>
        </AdditionalInformation>
        @endif
        <AdditionalInformation>
            <Name>COPAGO</Name>
            <Value>{{number_format($totalCoPayment, 2, '.', '')}}</Value>
        </AdditionalInformation>
        <AdditionalInformation>
            <Name>CUOTA_MODERADORA</Name>
            <Value>{{number_format($totalModeratingFee, 2, '.', '')}}</Value>
        </AdditionalInformation>
        <AdditionalInformation>
            <Name>PAGOS_COMPARTIDOS</Name>
            <Value>{{number_format($totalSharedPayment, 2, '.', '')}}</Value>
        </AdditionalInformation>
        <AdditionalInformation>
            <Name>ANTICIPO</Name>
            <Value>{{number_format($totalAdvancePayment, 2, '.', '')}}</Value>
        </AdditionalInformation>
    </Collection>
@endif
