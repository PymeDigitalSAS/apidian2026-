<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Umbral de saturación de CPU (%)
    |--------------------------------------------------------------------------
    | Cuando un snapshot detecta el CPU en este porcentaje o más, captura las
    | consultas SQL que estaban corriendo en ese instante para saber qué lo
    | está saturando.
    */
    'cpu_saturation' => (float) env('MONITOR_CPU_SATURATION', 85),

    /*
    |--------------------------------------------------------------------------
    | Umbral de petición lenta (milisegundos)
    |--------------------------------------------------------------------------
    | Las peticiones HTTP que tarden este tiempo o más se registran junto con
    | su consulta más lenta, para identificar qué ruta/acción satura el sistema.
    */
    'slow_request_ms' => (int) env('MONITOR_SLOW_REQUEST_MS', 3000),

    /*
    |--------------------------------------------------------------------------
    | Días de retención
    |--------------------------------------------------------------------------
    | Los snapshots y las peticiones lentas más antiguos que esto se eliminan
    | automáticamente.
    */
    'retention_days' => (int) env('MONITOR_RETENTION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Ruta de /proc para la captura de procesos
    |--------------------------------------------------------------------------
    | Por defecto se lee el /proc del propio entorno. Si el monitoreo corre en un
    | contenedor aislado y quieres ver los procesos del VPS (no solo los del
    | contenedor), monta el /proc del host en solo-lectura y apunta aquí:
    |
    |   docker-compose:  volumes: ["/proc:/host/proc:ro"]
    |   .env:            MONITOR_PROC_PATH=/host/proc
    |
    | Alternativa sin código: usar `pid: "host"` en el servicio y dejar /proc.
    */
    'proc_path' => env('MONITOR_PROC_PATH', '/proc'),

];
