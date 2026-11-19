@php
    $t14fecha = $date;
    try {
        $t14fecha = \Carbon\Carbon::parse($date)->format('d/m/Y');
    } catch (\Throwable $e) {
        $t14fecha = $date;
    }
@endphp
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td style="background-color: #E7EDF2; color: #243140; padding: 14px 36px 14px 36px; border-bottom: 3px solid #243140;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="width: 58%; vertical-align: middle; padding: 0;">
                        @if(!empty($imgLogo))
                            <img src="{{ $imgLogo }}" alt="logo" style="max-width: 150px; max-height: 42px; width: auto; height: auto;">
                            <br>
                        @endif
                        <div style="font-size: 15px; font-weight: bold; color: #243140; margin-top: 6px;">{{ mb_strtoupper($user->name ?? '') }}</div>
                        <div style="font-size: 8px; color: #5C6B78; margin-top: 2px;">{{ mb_strtoupper(optional($company->type_regime)->name ?? '') }}</div>
                    </td>
                    <td style="width: 42%; vertical-align: top; text-align: right; padding: 0;">
                        <div style="font-size: 26px; font-weight: bold; color: #243140; letter-spacing: 1px;">FACTURA</div>
                        @if((int) ($request->type_document_id ?? 1) === 3)
                            <div style="font-size: 8px; color: #5C6B78;">DE CONTINGENCIA</div>
                        @endif
                        <div style="font-size: 9px; color: #243140; text-align: right; margin-top: 4px;">
                            <strong>FACTURA No.</strong>&nbsp;&nbsp;{{ $resolution->prefix }}{{ $request->number }}<br>
                            <strong>FECHA</strong>&nbsp;&nbsp;{{ $t14fecha }}
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
