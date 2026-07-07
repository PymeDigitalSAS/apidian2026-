<footer>
    @isset($request->foot_note)
        <p style="text-align: center; color: #2E75B6; font-size: 8.5px; margin: 0 0 2px 0;"><strong>{{$request->foot_note}}</strong></p>
    @endisset
    <table width="100%" style="border-top: 0.5px solid #2E75B6; font-size: 7px; color: #444444;">
        <tr>
            <td style="width: 80%; padding: 3px 0 0 0;">
                Generada con {{ env('APP_NAME', 'API DIAN') }} · NIT: {{$company->identification_number}}-{{$company->dv}}
            </td>
            <td style="width: 20%; text-align: right; padding: 3px 0 0 0;">{PAGENO} / {nb}</td>
        </tr>
    </table>
</footer>
