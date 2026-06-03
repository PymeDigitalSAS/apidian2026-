<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Petición HTTP que superó el umbral de lentitud (MONITOR_SLOW_REQUEST_MS). La
 * registra el middleware MonitorSlowRequest junto con su consulta más lenta, y
 * se correlaciona con los picos de CPU para saber qué ruta los provocó.
 */
class MonitoringSlowRequest extends Model
{
    protected $table = 'monitoring_slow_requests';

    protected $casts = [
        'duration_ms'      => 'integer',
        'status'           => 'integer',
        'query_count'      => 'integer',
        'query_time_ms'    => 'integer',
        'slowest_query_ms' => 'integer',
        'user_id'          => 'integer',
    ];

    protected $fillable = [
        'method', 'url', 'route', 'duration_ms', 'status',
        'query_count', 'query_time_ms', 'slowest_query', 'slowest_query_ms',
        'user_id',
    ];
}
