<?php

namespace App\Support;

/**
 * Interpreta el envío y la consulta del set de pruebas.
 * Un set ya aceptado trae IsValid en falso y StatusMessage nulo;
 * eso debe continuar el cambio de ambiente.
 */
final class PasoProduccion
{
    public static function envio(array $resultado): array
    {
        if (self::contieneAceptado($resultado)) {
            return ['success' => true, 'already_accepted' => true];
        }

        $zipkey = self::buscarZipKey($resultado);
        if ($zipkey !== null) {
            return ['success' => true, 'zipkey' => $zipkey];
        }

        $mensaje = DianListado::texto($resultado['message'] ?? '');
        if ($mensaje === '') {
            $mensaje = self::descripcion($resultado) ?: 'No se obtuvo ZipKey.';
        }

        return ['error' => $mensaje];
    }

    public static function consultaZip(array $resultado): array
    {
        if (self::contieneAceptado($resultado)) {
            return ['success' => true, 'already_accepted' => true];
        }

        $nodo = DianListado::nodo($resultado);
        if (!$nodo || !self::esInvalido($nodo['IsValid'] ?? null)) {
            return ['success' => true];
        }

        $desc = DianListado::texto($nodo['StatusDescription'] ?? '') ?: 'Error desconocido';
        if (stripos($desc, 'proceso de validación') !== false || stripos($desc, 'proceso de validacion') !== false) {
            return ['error' => 'El documento está en proceso de validación en la DIAN. Por favor, espera unos minutos y vuelve a consultar el ZipKey.'];
        }

        $partes = array_filter([
            $desc,
            DianListado::texto($nodo['StatusMessage'] ?? ''),
            self::errores($nodo['ErrorMessage'] ?? null),
        ], function ($parte) {
            return $parte !== '';
        });

        return ['error' => implode('<br>', $partes)];
    }

    public static function contieneAceptado($valor): bool
    {
        return (bool) preg_match('/se encuentra aceptado/iu', self::aplanar($valor));
    }

    private static function buscarZipKey($valor): ?string
    {
        if (!is_array($valor)) {
            return null;
        }
        foreach ($valor as $clave => $item) {
            if ($clave === 'ZipKey') {
                $texto = DianListado::texto($item);
                if ($texto !== '') {
                    return $texto;
                }
                continue;
            }
            $encontrado = self::buscarZipKey($item);
            if ($encontrado !== null) {
                return $encontrado;
            }
        }

        return null;
    }

    private static function descripcion(array $resultado): string
    {
        $nodo = DianListado::nodo($resultado);

        return $nodo ? DianListado::texto($nodo['StatusDescription'] ?? '') : '';
    }

    private static function esInvalido($valor): bool
    {
        if (is_array($valor) && array_key_exists('_value', $valor)) {
            $valor = $valor['_value'];
        }

        return in_array($valor, [false, 'false', 'False', 0, '0'], true);
    }

    private static function errores($error): string
    {
        if (!is_array($error) || !isset($error['string'])) {
            return DianListado::texto($error);
        }
        $cadenas = $error['string'];
        if (!is_array($cadenas)) {
            return DianListado::texto($cadenas);
        }

        return implode('<br>', array_filter(array_map(function ($item) {
            return DianListado::texto($item);
        }, $cadenas)));
    }

    private static function aplanar($valor): string
    {
        if (is_string($valor) || is_numeric($valor)) {
            return trim((string) $valor);
        }
        if (!is_array($valor)) {
            return '';
        }
        if (($valor['_attributes']['nil'] ?? null) === 'true') {
            unset($valor['_attributes']);
        }
        $partes = [];
        foreach ($valor as $clave => $item) {
            if ($clave === '_attributes') {
                continue;
            }
            $texto = self::aplanar($item);
            if ($texto !== '') {
                $partes[] = $texto;
            }
        }

        return implode(' ', $partes);
    }
}
