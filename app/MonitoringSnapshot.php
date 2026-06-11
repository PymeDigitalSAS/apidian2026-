<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Instantánea de recursos del servidor (CPU/RAM/disco) tomada cada minuto por
 * el comando monitor:snapshot. Cuando el CPU supera el umbral, guarda además las
 * consultas SQL y procesos que estaban corriendo (top_queries / top_processes).
 */
class MonitoringSnapshot extends Model
{
    protected $table = 'monitoring_snapshots';

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'cpu_percent'  => 'float',
        'load_1'       => 'float',
        'load_5'       => 'float',
        'load_15'      => 'float',
        'cpu_cores'    => 'integer',
        'mem_total'    => 'integer',
        'mem_used'     => 'integer',
        'mem_percent'  => 'float',
        'disk_total'   => 'integer',
        'disk_used'    => 'integer',
        'disk_percent'  => 'float',
        'top_queries'   => 'array',
        'top_processes' => 'array',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'cpu_percent', 'load_1', 'load_5', 'load_15', 'cpu_cores',
        'mem_total', 'mem_used', 'mem_percent',
        'disk_total', 'disk_used', 'disk_percent',
        'top_queries', 'top_processes',
        'hostname', 'environment',
    ];
}
