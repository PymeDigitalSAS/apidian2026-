<?php

namespace App\Http\Controllers;

use App\MonitoringSnapshot;
use App\Services\SystemMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $current = $monitor->collect();
        $last    = MonitoringSnapshot::latest('id')->first();

        return view('monitoring.index', [
            'current' => $current,
            'last'    => $last,
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
     * Histórico de consumo para graficar. Parámetro ?hours=N (por defecto 6).
     */
    public function history(Request $request): JsonResponse
    {
        $hours = (int) $request->query('hours', 6);
        $hours = max(1, min($hours, 720)); // entre 1 hora y 30 días

        $snapshots = MonitoringSnapshot::where('created_at', '>=', now()->subHours($hours))
            ->orderBy('created_at')
            ->get([
                'id', 'cpu_percent', 'mem_percent', 'disk_percent',
                'load_1', 'db_connections', 'db_threads_running', 'created_at',
            ]);

        return response()->json([
            'hours'  => $hours,
            'points' => $snapshots->map(function (MonitoringSnapshot $s) {
                return [
                    't'           => $s->created_at->toIso8601String(),
                    'cpu'         => $s->cpu_percent,
                    'mem'         => $s->mem_percent,
                    'disk'        => $s->disk_percent,
                    'load'        => $s->load_1,
                    'connections' => $s->db_connections,
                    'threads'     => $s->db_threads_running,
                ];
            }),
        ]);
    }

    /**
     * Picos de consumo en el rango: snapshots con mayor CPU/RAM, útil para ver
     * "en qué momento se satura y con qué consultas".
     */
    public function peaks(Request $request): JsonResponse
    {
        $hours = (int) $request->query('hours', 24);
        $hours = max(1, min($hours, 720));

        $base = MonitoringSnapshot::where('created_at', '>=', now()->subHours($hours));

        $topCpu = (clone $base)->orderByDesc('cpu_percent')->limit(10)->get();
        $topMem = (clone $base)->orderByDesc('mem_percent')->limit(10)->get();

        $map = function (MonitoringSnapshot $s) {
            return [
                'id'            => $s->id,
                'at'            => $s->created_at->toDateTimeString(),
                'cpu'           => $s->cpu_percent,
                'mem'           => $s->mem_percent,
                'disk'          => $s->disk_percent,
                'connections'   => $s->db_connections,
                'top_queries'   => $s->top_queries ?? [],
                'top_processes' => $s->top_processes ?? [],
            ];
        };

        return response()->json([
            'hours'   => $hours,
            'top_cpu' => $topCpu->map($map)->values(),
            'top_mem' => $topMem->map($map)->values(),
        ]);
    }
}
