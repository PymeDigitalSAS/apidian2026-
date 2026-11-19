@php
    $t14fecha = $date;
    try {
        $t14fecha = \Carbon\Carbon::parse($date)->format('d/m/Y');
    } catch (\Throwable $e) {
        $t14fecha = $date;
    }

    // La franja es oscura. Si el dibujo del logo tambien lo es, se pone sobre
    // una placa blanca; un logo claro se deja directo sobre la franja.
    $t14placa = false;
    if (!empty($imgLogo) && function_exists('imagecreatefromstring')
        && preg_match('#^data:image/(?:png|jpeg|jpg|gif);base64,(.+)$#i', $imgLogo, $t14logo)) {
        $t14bin = base64_decode($t14logo[1], true);
        $t14im = $t14bin ? @imagecreatefromstring($t14bin) : false;
        if ($t14im) {
            if (!imageistruecolor($t14im)) {
                imagepalettetotruecolor($t14im);
            }
            $t14w = imagesx($t14im);
            $t14h = imagesy($t14im);
            $t14sum = 0;
            $t14n = 0;
            $t14sx = max(1, (int) floor($t14w / 28));
            $t14sy = max(1, (int) floor($t14h / 28));
            for ($t14y = 0; $t14y < $t14h; $t14y += $t14sy) {
                for ($t14x = 0; $t14x < $t14w; $t14x += $t14sx) {
                    $t14px = imagecolorat($t14im, $t14x, $t14y);
                    $t14a = ($t14px >> 24) & 0x7F;
                    if ($t14a > 96) {
                        continue;
                    }
                    $t14r = ($t14px >> 16) & 0xFF;
                    $t14g = ($t14px >> 8) & 0xFF;
                    $t14b = $t14px & 0xFF;
                    $t14sum += (0.299 * $t14r) + (0.587 * $t14g) + (0.114 * $t14b);
                    $t14n++;
                }
            }
            imagedestroy($t14im);
            $t14placa = $t14n > 0 && ($t14sum / $t14n) < 155;
        }
    }
@endphp
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td style="background-color: #26323E; color: #ffffff; padding: 14px 36px 16px 36px;">
            <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="width: 58%; vertical-align: middle; padding: 0;">
                        @if(!empty($imgLogo))
                            @if($t14placa)
                                <table cellpadding="0" cellspacing="0" style="margin: 0 0 6px 0;">
                                    <tr>
                                        <td bgcolor="#ffffff" style="background-color: #ffffff; padding: 4px 6px;">
                                            <img src="{{ $imgLogo }}" alt="logo" style="max-width: 120px; max-height: 38px; width: auto; height: auto;">
                                        </td>
                                    </tr>
                                </table>
                            @else
                                <img src="{{ $imgLogo }}" alt="logo" style="max-width: 150px; max-height: 40px; width: auto; height: auto; margin-bottom: 4px;">
                                <br>
                            @endif
                        @endif
                        <div style="font-size: 15px; font-weight: bold; color: #ffffff; margin-top: 6px;">{{ mb_strtoupper($user->name ?? '') }}</div>
                        <div style="font-size: 8px; color: #d5dde6; margin-top: 2px;">{{ mb_strtoupper(optional($company->type_regime)->name ?? '') }}</div>
                    </td>
                    <td style="width: 42%; vertical-align: top; text-align: right; padding: 0;">
                        <div style="font-size: 26px; font-weight: bold; color: #ffffff;">FACTURA</div>
                        @if((int) ($request->type_document_id ?? 1) === 3)
                            <div style="font-size: 8px; color: #d5dde6;">DE CONTINGENCIA</div>
                        @endif
                        <div style="font-size: 9px; color: #ffffff; text-align: right; margin-top: 4px;">
                            <strong>FACTURA No.</strong>&nbsp;&nbsp;{{ $resolution->prefix }}{{ $request->number }}<br>
                            <strong>FECHA</strong>&nbsp;&nbsp;{{ $t14fecha }}
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
