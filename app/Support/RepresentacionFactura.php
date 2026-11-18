<?php

namespace App\Support;

use App\Custom\NumberSpellOut;

/**
 * Datos opcionales de la representación gráfica de la factura.
 * Se activan por empresa y solo se imprimen si la plantilla aún no los trae.
 */
final class RepresentacionFactura
{
    public static function porDefecto(): array
    {
        return [
            'actividad' => ['on' => false, 'value' => '9603'],
            'ica' => ['on' => false, 'value' => '10x1000'],
            'ica_anual' => ['on' => false],
            'letras' => ['on' => false],
        ];
    }

    public static function deEmpresa($company): array
    {
        $base = self::porDefecto();
        $guardado = $company->invoice_graphic ?? [];
        if (!is_array($guardado)) {
            return $base;
        }

        foreach (['actividad', 'ica'] as $clave) {
            if (!isset($guardado[$clave]) || !is_array($guardado[$clave])) {
                continue;
            }
            $base[$clave]['on'] = (bool) ($guardado[$clave]['on'] ?? false);
            $valor = trim((string) ($guardado[$clave]['value'] ?? ''));
            if ($valor !== '') {
                $base[$clave]['value'] = $valor;
            }
        }
        foreach (['ica_anual', 'letras'] as $clave) {
            if (isset($guardado[$clave]['on'])) {
                $base[$clave]['on'] = (bool) $guardado[$clave]['on'];
            }
        }

        return $base;
    }

    public static function normalizar(array $entrada): array
    {
        $base = self::porDefecto();
        foreach (['actividad', 'ica'] as $clave) {
            $item = $entrada[$clave] ?? [];
            $base[$clave]['on'] = filter_var($item['on'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $valor = trim(strip_tags((string) ($item['value'] ?? '')));
            $base[$clave]['value'] = $valor !== '' ? mb_substr($valor, 0, 40) : $base[$clave]['value'];
        }
        foreach (['ica_anual', 'letras'] as $clave) {
            $base[$clave]['on'] = filter_var($entrada[$clave]['on'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        return $base;
    }

    /**
     * Completa el request con lo activado en la empresa y devuelve el HTML
     * que la plantilla elegida todavía no imprime.
     */
    public static function aplicar($request, $company, string $carpeta, $plantilla, $retenciones = null): string
    {
        $datos = self::deEmpresa($company);
        $fuente = self::fuente($carpeta, $plantilla);
        $lineas = [];

        if ($datos['actividad']['on'] && self::vacio($request, 'actividadeconomica')) {
            if (strpos($fuente, 'actividadeconomica') !== false) {
                self::poner($request, 'actividadeconomica', $datos['actividad']['value']);
            } else {
                $lineas[] = 'ACTIVIDAD ECONOMICA: '.e($datos['actividad']['value']);
            }
        }

        if ($datos['ica']['on'] && self::vacio($request, 'tarifaica')) {
            $texto = 'TARIFA ICA: '.e($datos['ica']['value']).self::sufijoIca($datos['ica']['value']);
            if (strpos($fuente, 'tarifaica') !== false) {
                self::poner($request, 'tarifaica', $datos['ica']['value']);
            } else {
                $lineas[] = $texto;
            }
        }

        $quiereAnual = $datos['ica_anual']['on'] || (bool) self::leer($request, 'presentacion_ica_anual');
        if ($quiereAnual && strpos($fuente, 'presentacion_ica_anual') !== false) {
            self::poner($request, 'presentacion_ica_anual', true);
        } elseif ($quiereAnual) {
            $lineas[] = 'PRESENTACION DE ICA ANUAL';
        }

        $quiereLetras = $datos['letras']['on'] || (bool) self::leer($request, 'valor_en_letras');
        if ($quiereLetras && strpos($fuente, 'valor_en_letras') !== false) {
            self::poner($request, 'valor_en_letras', true);
        } elseif ($quiereLetras && !preg_match('/\bSON\b|PRECIO EN LETRAS/', $fuente)) {
            $lineas[] = '<strong>SON</strong>: '.e(self::enLetras($request, $retenciones)).' M/CTE.';
        }

        if ($lineas === []) {
            return '';
        }

        return '<div style="font-size:10px; margin-top:8px; line-height:1.45;">'.implode('<br>', $lineas).'</div>';
    }

    public static function sufijoIca($valor): string
    {
        return is_numeric($valor) ? '‰' : '';
    }

    private static function enLetras($request, $retenciones): string
    {
        $totales = self::leer($request, 'legal_monetary_totals');
        if (!is_array($totales)) {
            $totales = self::leer($request, 'requested_monetary_totals');
        }
        $totales = is_array($totales) ? $totales : [];
        $total = (float) ($totales['payable_amount'] ?? 0);
        $anterior = self::leer($request, 'previous_balance');
        if (is_numeric($anterior)) {
            $total += (float) $anterior;
        }
        $total -= self::sumaRetenciones($retenciones);

        return (new NumberSpellOut())->convertir(round($total, 2), self::leer($request, 'idcurrency') ?: null);
    }

    private static function sumaRetenciones($retenciones): float
    {
        $suma = 0.0;
        foreach (is_array($retenciones) ? $retenciones : [] as $item) {
            if (is_array($item)) {
                $suma += (float) ($item['tax_amount'] ?? 0);
            } elseif (is_object($item)) {
                $suma += (float) ($item->tax_amount ?? 0);
            }
        }

        return $suma;
    }

    private static function fuente(string $carpeta, $plantilla): string
    {
        $texto = '';
        $vistos = [];
        $pendientes = [
            resource_path("views/pdfs/{$carpeta}/template{$plantilla}.blade.php"),
            resource_path("views/pdfs/{$carpeta}/header{$plantilla}.blade.php"),
        ];
        while ($pendientes) {
            $ruta = array_pop($pendientes);
            if (!is_file($ruta) || isset($vistos[$ruta])) {
                continue;
            }
            $vistos[$ruta] = true;
            $contenido = (string) file_get_contents($ruta);
            $texto .= "\n".$contenido;
            if (preg_match_all("/@include\\('([^']+)'\\)/", $contenido, $coincidencias)) {
                foreach ($coincidencias[1] as $vista) {
                    $pendientes[] = resource_path('views/'.str_replace('.', '/', $vista).'.blade.php');
                }
            }
        }

        return $texto;
    }

    private static function vacio($request, string $clave): bool
    {
        $valor = self::leer($request, $clave);

        return $valor === null || $valor === '';
    }

    private static function leer($request, string $clave)
    {
        if (is_object($request) && method_exists($request, 'input')) {
            $valor = $request->input($clave);
            if ($valor !== null && $valor !== '') {
                return $valor;
            }
        }

        return is_object($request) ? ($request->{$clave} ?? null) : null;
    }

    private static function poner($request, string $clave, $valor): void
    {
        if (is_object($request) && method_exists($request, 'merge')) {
            $request->merge([$clave => $valor]);

            return;
        }
        if (is_object($request)) {
            $request->{$clave} = $valor;
        }
    }
}
