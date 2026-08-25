<?php

namespace App\Helpers;

use Closure;

class ResolutionNumberRule
{
    /**
     * Reglas de validacion para el consecutivo (number/consecutive) contra el
     * rango from/to de la resolucion. Si la resolucion registrada tiene el
     * rango vacio o no numerico, devuelve una regla que falla con un mensaje
     * claro, en lugar de armar 'between:,' que revienta con la excepcion
     * generica: The given value "" does not represent a valid number.
     */
    public static function make($resolution, string $base = 'required|integer')
    {
        $from = $resolution->from ?? null;
        $to = $resolution->to ?? null;

        if (is_null($from) || is_null($to)) {
            return $base;
        }

        if (is_numeric($from) && is_numeric($to)) {
            return $base.'|between:'.$from.','.$to;
        }

        $rules = explode('|', $base);
        $rules[] = function (string $attribute, $value, Closure $fail) use ($resolution) {
            $name = trim(($resolution->prefix ?? '').' '.($resolution->resolution ?? ''));
            $fail("La resolución {$name} tiene el rango de numeración (from/to) vacío o inválido. Registre nuevamente la resolución indicando sus valores 'from' y 'to'.");
        };

        return $rules;
    }
}
