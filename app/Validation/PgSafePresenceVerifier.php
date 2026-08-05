<?php

namespace App\Validation;

use Illuminate\Database\QueryException;
use Illuminate\Validation\DatabasePresenceVerifier;

/**
 * Verificador de presencia (reglas exists/unique) tolerante a PostgreSQL.
 *
 * En MySQL, "exists:tabla,id" con un valor no numerico se coacciona en
 * silencio y la validacion simplemente falla (HTTP 422). En PostgreSQL la
 * misma consulta lanza SQLSTATE 22P02 (invalid input syntax for type
 * bigint) y el request muere con un 500. Hay ~580 reglas exists:*,id en
 * los requests del API sin guard "integer|", asi que en lugar de tocarlas
 * todas, este verificador convierte el error de tipo en "no existe":
 * mismo 422 que siempre dio MySQL.
 */
class PgSafePresenceVerifier extends DatabasePresenceVerifier
{
    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = [])
    {
        try {
            return parent::getCount($collection, $column, $value, $excludeId, $idColumn, $extra);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '22P02') {
                return 0;
            }
            throw $e;
        }
    }

    public function getMultiCount($collection, $column, array $values, array $extra = [])
    {
        try {
            return parent::getMultiCount($collection, $column, $values, $extra);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '22P02') {
                return 0;
            }
            throw $e;
        }
    }
}
