<?php

namespace App\Http\Controllers;

use App\MonitoringSnapshot;
use App\MonitoringSlowRequest;
use App\Services\SystemMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MonitoringController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');

        // Solo el administrador de la plataforma (sin empresa asociada) puede
        // ver los recursos del servidor: es información sensible.
        $this->middleware(function ($request, $next) {
            $user = auth()->user();
            if (!$user || !method_exists($user, 'isPlatformAdmin') || !$user->isPlatformAdmin()) {
                abort(403, 'No autorizado.');
            }
            return $next($request);
        });
    }

    /**
     * Página principal del módulo de monitoreo.
     */
    public function index(SystemMonitorService $monitor)
    {
        return view('monitoring.index', [
            'current' => $monitor->collect(),
        ]);
    }

    /**
     * Métricas en tiempo real (se recolectan al instante). Usado por el
     * auto-refresh de la vista.
     */
    public function live(SystemMonitorService $monitor): JsonResponse
    {
        $data = $monitor->collect();
        $data['server_time'] = now()->toDateTimeString();

        return response()->json($data);
    }

    /**
     * Histórico de consumo de CPU, memoria y disco. Parámetro ?hours=N.
     */
    public function history(Request $request): JsonResponse
    {
        $hours = (int) $request->query('hours', 6);
        $hours = max(1, min($hours, 720)); // entre 1 hora y 30 días

        $snapshots = MonitoringSnapshot::where('created_at', '>=', now()->subHours($hours))
            ->orderBy('created_at')
            ->get(['id', 'cpu_percent', 'mem_percent', 'disk_percent', 'created_at']);

        return response()->json([
            'hours'  => $hours,
            'points' => $snapshots->map(function (MonitoringSnapshot $s) {
                return [
                    't'    => $s->created_at->toIso8601String(),
                    'cpu'  => $s->cpu_percent,
                    'mem'  => $s->mem_percent,
                    'disk' => $s->disk_percent,
                ];
            }),
        ]);
    }

    /**
     * Momentos de mayor uso de CPU en el rango (para ver cuándo se satura).
     * Incluye las consultas SQL capturadas en cada pico (si hubo saturación).
     */
    public function peaks(Request $request): JsonResponse
    {
        $hours = (int) $request->query('hours', 24);
        $hours = max(1, min($hours, 720));

        $rows = MonitoringSnapshot::where('created_at', '>=', now()->subHours($hours))
            ->orderByDesc('cpu_percent')
            ->limit(10)
            ->get(['id', 'cpu_percent', 'mem_percent', 'disk_percent', 'top_queries', 'top_processes', 'created_at']);

        return response()->json([
            'hours' => $hours,
            'peaks' => $rows->map(function (MonitoringSnapshot $s) {
                // Peticiones lentas que ocurrieron alrededor de este pico (±90s).
                $from = $s->created_at->copy()->subSeconds(90);
                $to   = $s->created_at->copy()->addSeconds(90);
                $slow = MonitoringSlowRequest::whereBetween('created_at', [$from, $to])
                    ->orderByDesc('duration_ms')
                    ->limit(10)
                    ->get()
                    ->map(function (MonitoringSlowRequest $r) {
                        return [
                            'at'               => $r->created_at->toDateTimeString(),
                            'method'           => $r->method,
                            'route'            => $r->route,
                            'url'              => $r->url,
                            'duration_ms'      => $r->duration_ms,
                            'query_count'      => $r->query_count,
                            'slowest_query'    => $r->slowest_query,
                            'slowest_query_ms' => $r->slowest_query_ms,
                        ];
                    });

                return [
                    'at'            => $s->created_at->toDateTimeString(),
                    'cpu'           => $s->cpu_percent,
                    'mem'           => $s->mem_percent,
                    'disk'          => $s->disk_percent,
                    // Hubo captura de saturación si alguna de las columnas no es null
                    // (se llenan solo cuando el CPU superó el umbral), o si hay
                    // peticiones lentas correlacionadas.
                    'captured'      => $s->top_queries !== null || $s->top_processes !== null || $slow->isNotEmpty(),
                    'top_queries'   => $s->top_queries ?? [],
                    'top_processes' => $s->top_processes ?? [],
                    'slow_requests' => $slow,
                ];
            }),
        ]);
    }

    /**
     * Consumo por empresa: espacio en base de datos y en storage por cada NIT.
     * Se cachea porque recorrer carpetas en disco es costoso; ?refresh=1 fuerza
     * el recálculo.
     */
    public function companies(Request $request, SystemMonitorService $monitor): JsonResponse
    {
        if ($request->boolean('refresh')) {
            Cache::forget('monitoring.company_usage');
        }

        $data = Cache::remember('monitoring.company_usage', now()->addMinutes(5), function () use ($monitor) {
            return $monitor->companyUsage();
        });

        return response()->json($data);
    }
}
