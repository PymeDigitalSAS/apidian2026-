<?php

namespace App\Support;

use App\Document;
use Illuminate\Support\Facades\DB;

/**
 * El listado de APIDIAN (documents.cufe / response_dian / state_document_id)
 * no se alimentaba en habilitación: SendTestSetAsync no trae IsValid=true.
 */
final class DianListado
{
    public static function texto($valor): string
    {
        if (is_array($valor) && array_key_exists('_value', $valor)) {
            $valor = $valor['_value'];
        }
        if ($valor === null || is_array($valor) || is_object($valor)) {
            return '';
        }

        return trim((string) $valor);
    }

    public static function nodo(array $resultado): ?array
    {
        $dian = $resultado['ResponseDian'] ?? [];
        if (is_string($dian)) {
            $dian = json_decode($dian, true) ?: [];
        }
        if (!is_array($dian)) {
            return null;
        }
        foreach (['GetStatusZip', 'SendBillSync', 'GetStatus', 'SendTestSetAsync'] as $operacion) {
            $nodo = $dian['Envelope']['Body'][$operacion.'Response'][$operacion.'Result'] ?? null;
            if (!is_array($nodo)) {
                continue;
            }
            if (isset($nodo['DianResponse']) && is_array($nodo['DianResponse'])) {
                $nodo = $nodo['DianResponse'];
            }
            if (array_is_list($nodo)) {
                if (count($nodo) !== 1 || !is_array($nodo[0] ?? null)) {
                    continue;
                }
                $nodo = $nodo[0];
            }

            return $nodo;
        }

        return null;
    }

    public static function estado(array $resultado): string
    {
        $nodo = self::nodo($resultado);
        if (!$nodo) {
            return 'pendiente';
        }
        $codigo = self::texto($nodo['StatusCode'] ?? '');
        $valido = $nodo['IsValid'] ?? null;
        if (is_array($valido) && array_key_exists('_value', $valido)) {
            $valido = $valido['_value'];
        }
        $descripcion = self::texto($nodo['StatusDescription'] ?? '');
        if ($descripcion === '') {
            $descripcion = self::texto($nodo['StatusMessage'] ?? '');
        }
        if ($codigo === '00' && in_array($valido, [true, 'true', 'True', 1, '1'], true)) {
            return 'validado';
        }
        if ($codigo === '2' && preg_match('/aceptado/iu', $descripcion)) {
            return 'validado';
        }
        if ($codigo === '99' && in_array($valido, [false, 'false', 'False', 0, '0'], true)) {
            return 'rechazado';
        }

        return 'pendiente';
    }

    public static function resultadoOperacion(object $op): array
    {
        $original = json_decode($op->respuesta ?? '{}', true) ?: [];
        if (!is_array($original)) {
            $original = [];
        }
        $consulta = DB::table('erp_consultas_fiscales')->where('operacion_id', $op->id)->orderByDesc('id')->first();
        if (!$consulta) {
            return $original;
        }
        $extra = json_decode($consulta->respuesta ?? '{}', true) ?: [];

        return is_array($extra) ? array_replace($original, $extra) : $original;
    }

    public static function aplicar(Document $doc, array $resultado): void
    {
        $cufe = $resultado['cufe'] ?? $resultado['cude'] ?? $resultado['cufecude'] ?? '';
        $nodo = self::nodo($resultado);
        if ($nodo) {
            $clave = self::texto($nodo['XmlDocumentKey'] ?? '');
            if ($clave !== '') {
                $cufe = $clave;
            }
        }
        if (is_string($cufe) && $cufe !== '') {
            $doc->cufe = $cufe;
        }
        if (isset($resultado['ResponseDian'])) {
            $doc->response_dian = is_string($resultado['ResponseDian'])
                ? $resultado['ResponseDian']
                : json_encode($resultado['ResponseDian']);
        }
        if (self::estado($resultado) === 'validado') {
            $doc->state_document_id = 1;
        }
        $doc->save();
    }

    public static function sincronizarOperacion(object $op, ?array $resultado = null): void
    {
        $resultado = $resultado ?? self::resultadoOperacion($op);
        $prefijo = (string) ($op->prefijo ?? '');
        $numero = (int) ($op->numero ?? 0);
        if ($prefijo === '' || $numero < 1) {
            return;
        }
        $nit = DB::table('companies')->where('id', $op->empresa_id)->value('identification_number');
        if (!$nit) {
            return;
        }
        $doc = Document::query()
            ->where('identification_number', $nit)
            ->where('prefix', $prefijo)
            ->where('number', $numero)
            ->latest('id')
            ->first();
        if (!$doc) {
            return;
        }
        self::aplicar($doc, $resultado);
    }
}
