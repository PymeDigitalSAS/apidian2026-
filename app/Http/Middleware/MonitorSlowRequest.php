<?php

namespace App\Http\Middleware;

use App\MonitoringSlowRequest;
use App\Services\SlowRequestRecorder;
use Closure;
use Illuminate\Http\Request;
use Throwable;

/**
 * Mide la duración de cada petición HTTP y registra las que superan el umbral
 * de "lentitud" (MONITOR_SLOW_REQUEST_MS, por defecto 3000 ms), junto con la
 * consulta más lenta que ejecutaron. Sirve para saber qué ruta/acción de la
 * aplicación está saturando el sistema.
 */
class MonitorSlowRequest
{
    public function handle(Request $request, Closure $next)
    {
        app(SlowRequestRecorder::class)->start();

        return $next($request);
    }

    /**
     * Se ejecuta después de enviar la respuesta: no afecta el tiempo del cliente.
     */
    public function terminate(Request $request, $response): void
    {
        try {
            /** @var SlowRequestRecorder $recorder */
            $recorder = app(SlowRequestRecorder::class);
            if (!$recorder->started()) {
                return;
            }

            $durationMs = $recorder->elapsedMs();
            $threshold  = (int) config('monitoring.slow_request_ms', 3000);

            if ($durationMs < $threshold) {
                return;
            }

            $summary = $recorder->summary();

            MonitoringSlowRequest::create(array_merge([
                'method'      => $request->getMethod(),
                'url'         => $request->fullUrl(),
                'route'       => optional($request->route())->getName()
                                    ?? optional($request->route())->uri(),
                'duration_ms' => (int) round($durationMs),
                'status'      => method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null,
                'user_id'     => optional($request->user())->id,
            ], $summary));
        } catch (Throwable $e) {
            // El monitoreo nunca debe romper una petición.
        }
    }
}
