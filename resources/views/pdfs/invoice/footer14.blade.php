<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td style="background-color: #0C324A; color: #ffffff; padding: 8px 36px; text-align: center;">
            <div style="font-size: 9px;">{{ $user->email ?? $company->phone }}</div>
            <div style="font-size: 7px; color: #d5dde6; margin-top: 2px;">
                Resolución {{ $resolution->resolution }}
                @if(!empty($resolution->resolution_date)) de {{ $resolution->resolution_date }} @endif
                · Prefijo {{ $resolution->prefix }} · Rango {{ $resolution->from }} al {{ $resolution->to }}
                @if(!empty($cufecude))
                    <br>{{ ((int) ($request->type_document_id ?? 1) === 3) ? 'CUDE' : 'CUFE' }}: {{ $cufecude }}
                @endif
            </div>
        </td>
        @if(!empty($imageQr))
            <td style="background-color: #0C324A; width: 58px; padding: 4px 10px 4px 0; text-align: right;">
                <img src="{{ $imageQr }}" alt="QR" style="width: 46px; height: 46px; background: #ffffff;">
            </td>
        @endif
    </tr>
</table>
