<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Liberación de espacio: borrado en segundo plano de los archivos reales de una
 * empresa (o de todas), opcionalmente filtrado por fecha. Acción irreversible;
 * se procesa con el Job App\Jobs\PurgeFiles.
 */
class Purge extends Model
{
    protected $table = 'purges';

    protected $fillable = [
        'scope', 'company_id', 'nit', 'name',
        'filter_from', 'filter_to', 'filter_label',
        'status', 'deleted_files', 'freed_bytes', 'error', 'requested_by',
    ];

    protected $casts = [
        'filter_from'   => 'integer',
        'filter_to'     => 'integer',
        'deleted_files' => 'integer',
        'freed_bytes'   => 'integer',
    ];
}
