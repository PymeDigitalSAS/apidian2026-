<footer>
    <table width="100%" style="border-top: 0.5px solid #999999; font-size: 7.5px; color: #444444;">
        <tr>
            <td style="width: 82%; padding: 3px 0 0 0;">
                @if($request->type_document_id == 3)
                    Factura Electrónica de Contingencia No: {{$resolution->prefix}}-{{$request->number}} · Generada: {{$date}} {{$time}} · NIT: {{$company->identification_number}}-{{$company->dv}}<br>
                    CUDE: {{$cufecude}}
                @else
                    Factura Electrónica de Venta No: {{$resolution->prefix}}-{{$request->number}} · Generada: {{$date}} {{$time}} · NIT: {{$company->identification_number}}-{{$company->dv}}<br>
                    CUFE: {{$cufecude}}
                @endif
            </td>
            <td style="width: 18%; text-align: right; vertical-align: top; padding: 3px 0 0 0;">{PAGENO} / {nb}</td>
        </tr>
    </table>
    @isset($request->foot_note)
        <p style="font-size: 8px; text-align: center; margin: 3px 0 0 0;"><strong>{{$request->foot_note}}</strong></p>
    @endisset
</footer>
