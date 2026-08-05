<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Expresiones SQL crudas que difieren entre MySQL/MariaDB y PostgreSQL.
 * Todo el SQL escrito a mano (whereRaw, selectRaw, DB::raw) que use
 * funciones no portables debe construirse con estos metodos para que la
 * aplicacion funcione igual en ambos motores.
 */
class SqlDialect
{
    public static function driver()
    {
        return DB::connection()->getDriverName();
    }

    public static function isPgsql()
    {
        return self::driver() === 'pgsql';
    }

    /**
     * CAST de una columna varchar a entero (consecutivos guardados como
     * texto). UNSIGNED solo existe en MySQL/MariaDB; en PostgreSQL se usa
     * BIGINT.
     */
    public static function castInt($column)
    {
        return self::isPgsql()
            ? "CAST($column AS BIGINT)"
            : "CAST($column AS UNSIGNED)";
    }

    /**
     * CAST de una columna a texto. En PostgreSQL "AS CHAR" equivale a
     * char(1) y trunca silenciosamente, por eso alli se usa TEXT.
     */
    public static function castChar($column)
    {
        return self::isPgsql()
            ? "CAST($column AS TEXT)"
            : "CAST($column AS CHAR)";
    }

    /**
     * Extrae una propiedad de una columna JSON como texto sin comillas.
     * MySQL: JSON_UNQUOTE(col->'$.key') / PostgreSQL: col->>'key'.
     */
    public static function jsonText($column, $key)
    {
        return self::isPgsql()
            ? "$column->>'$key'"
            : "JSON_UNQUOTE($column->'$.$key')";
    }

    /**
     * Segundos epoch de una columna datetime (para agrupar por buckets
     * de tiempo). UNIX_TIMESTAMP no existe en PostgreSQL.
     */
    public static function epoch($column)
    {
        return self::isPgsql()
            ? "EXTRACT(EPOCH FROM $column)"
            : "UNIX_TIMESTAMP($column)";
    }

    /**
     * Funcion caracter-desde-codigo ASCII: CHAR() en MySQL, CHR() en
     * PostgreSQL.
     */
    public static function chr($code)
    {
        return self::isPgsql() ? "CHR($code)" : "CHAR($code)";
    }

    /**
     * Operador LIKE insensible a mayusculas. En MySQL LIKE ya es
     * case-insensitive por la collation *_ci; en PostgreSQL es sensible,
     * por eso alli se usa ILIKE.
     */
    public static function likeOp()
    {
        return self::isPgsql() ? 'ILIKE' : 'LIKE';
    }

    /**
     * Normaliza una expresion para comparar texto ignorando mayusculas Y
     * acentos ("MEDELLIN" debe encontrar "Medellín"). La collation *_ci de
     * MySQL ya ignora acentos, basta UPPER; en PostgreSQL se necesita la
     * extension unaccent (la crea la migracion enable_unaccent_extension).
     * Usar en ambos lados de la comparacion: fold('name') LIKE fold('?').
     */
    public static function fold($expr)
    {
        return self::isPgsql()
            ? "unaccent(UPPER($expr))"
            : "UPPER($expr)";
    }
}
