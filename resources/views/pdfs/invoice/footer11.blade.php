<footer>
    @isset($request->foot_note)
        <p style="font-size: 8px; text-align: center; margin: 0 0 2px 0;"><strong>{{$request->foot_note}}</strong></p>
    @endisset
    <table width="100%" style="font-size: 8px; color: #333333;">
        <tr>
            <td style="width: 80%; padding: 0;">
                Generada con {{ env('APP_NAME', 'API DIAN') }} · NIT: {{$company->identification_number}}-{{$company->dv}}
            </td>
            <td style="width: 20%; text-align: right; padding: 0;">{PAGENO}</td>
        </tr>
    </table>
</footer>
