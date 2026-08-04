<?php

namespace App\Http\Controllers;

use App\Helpers\SqlDialect;
use App\MonitoringSnapshot;
use App\MonitoringSlowRequest;
use App\Services\SystemMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Panel de monitoreo del servidor (solo para el admin de la plataforma). Expone:
 * métricas en vivo (live), histórico para el gráfico (history), picos de
 * saturación con su detalle (peaks) y consumo de disco por empresa (companies).
 * Los listados van paginados para no recargar la BD en cada consulta.
 */
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

       
        $targetPoints = 360;
        $bucket = max(10, (int) ceil($hours * 3600 / $targetPoints));

        $rows = MonitoringSnapshot::where('created_at', '>=', now()->subHours($hours))
            ->selectRaw('MIN(created_at) as t')
            // CAST a DECIMAL: en PostgreSQL ROUND(x, n) no acepta double precision
            ->selectRaw('ROUND(CAST(AVG(cpu_percent) AS DECIMAL(20,6)), 1) as cpu')
            ->selectRaw('ROUND(CAST(AVG(mem_percent) AS DECIMAL(20,6)), 1) as mem')
            ->selectRaw('ROUND(CAST(AVG(disk_percent) AS DECIMAL(20,6)), 1) as disk')
            ->groupByRaw('FLOOR(' . SqlDialect::epoch('created_at') . ' / ?)', [$bucket])
            ->orderByRaw('MIN(created_at)')
            ->get();

        return response()->json([
            'hours'  => $hours,
            'bucket' => $bucket,
            'points' => $rows->map(function ($r) {
                return [
                    't'    => \Illuminate\Support\Carbon::parse($r->t)->toIso8601String(),
                    'cpu'  => $r->cpu !== null ? (float) $r->cpu : null,
                    'mem'  => $r->mem !== null ? (float) $r->mem : null,
                    'disk' => $r->disk !== null ? (float) $r->disk : null,
                ];
            }),
        ]);
    }

    /**
     * Momentos de saturación de CPU en el rango (cuándo y qué lo saturó),
     * ordenados por fecha y PAGINADOS (?page=N). Carga las peticiones lentas de
     * toda la página en una sola consulta para no hacer N+1.
     */
    public function peaks(Request $request): JsonResponse
    {
        $hours = max(1, min((int) $request->query('hours', 24), 720));
        $page  = max(1, (int) $request->query('page', 1));
        $perPage = 15;

        $columns = [
            'id', 'cpu_percent', 'mem_percent', 'disk_percent',
            'load_1', 'load_5', 'load_15', 'cpu_cores',
            'mem_used', 'mem_total', 'disk_used', 'disk_total',
            'top_queries', 'top_processes', 'created_at',
        ];

        // Eventos con captura de saturación (top_queries/top_processes no nulos),
        // del más reciente al más antiguo. Paginados para no traer todo de golpe.
        $paginator = MonitoringSnapshot::where('created_at', '>=', now()->subHours($hours))
            ->where(function ($q) {
                $q->whereNotNull('top_processes')->orWhereNotNull('top_queries');
            })
            ->orderByDesc('created_at')
            ->paginate($perPage, $columns, 'page', $page);

        $rows     = collect($paginator->items());
        $lastPage = $paginator->lastPage();
        $total    = $paginator->total();

        // Si no hubo ninguna saturación en el rango, mostrar igualmente los
        // momentos de mayor CPU (una sola página) para no dejar la tabla vacía.
        if ($total === 0) {
            $rows = MonitoringSnapshot::where('created_at', '>=', now()->subHours($hours))
                ->orderByDesc('cpu_percent')
                ->limit($perPage)
                ->get($columns)
                ->sortByDesc('created_at')
                ->values();
            $lastPage = 1;
            $total    = $rows->count();
        }

        // Una sola consulta para TODAS las peticiones lentas de la ventana de la
        // página (evita una consulta por pico). Luego se reparten en memoria.
        $slowAll = collect();
        if ($rows->isNotEmpty()) {
            $stamps = $rows->pluck('created_at');
            $slowAll = MonitoringSlowRequest::whereBetween('created_at', [
                $stamps->min()->copy()->subSeconds(90),
                $stamps->max()->copy()->addSeconds(90),
            ])->orderByDesc('duration_ms')->get();
        }

        return response()->json([
            'hours'     => $hours,
            'page'      => $page,
            'last_page' => $lastPage,
            'total'     => $total,
            'peaks'     => $rows->map(function (MonitoringSnapshot $s) use ($slowAll) {
                // Peticiones lentas alrededor de este pico (±90s), ya en memoria.
                $from = $s->created_at->copy()->subSeconds(90);
                $to   = $s->created_at->copy()->addSeconds(90);
                $slow = $slowAll
                    ->filter(fn (MonitoringSlowRequest $r) => $r->created_at->between($from, $to))
                    ->take(10)
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
                    })
                    ->values();

                return [
                    'at'            => $s->created_at->toDateTimeString(),
                    'cpu'           => $s->cpu_percent,
                    'mem'           => $s->mem_percent,
                    'disk'          => $s->disk_percent,
                    'load_1'        => $s->load_1,
                    'load_5'        => $s->load_5,
                    'load_15'       => $s->load_15,
                    'cpu_cores'     => $s->cpu_cores,
                    'mem_used'      => $s->mem_used,
                    'mem_total'     => $s->mem_total,
                    'disk_used'     => $s->disk_used,
                    'disk_total'    => $s->disk_total,
                    // Hay detalle que mostrar si se capturó algo o hay peticiones lentas.
                    'captured'      => $s->top_queries !== null || $s->top_processes !== null || $slow->isNotEmpty(),
                    'top_queries'   => $s->top_queries ?? [],
                    'top_processes' => $s->top_processes ?? [],
                    'slow_requests' => $slow,
                ];
            })->values(),
        ]);
    }

    /**
     * Consumo de disco por empresa, PAGINADO (?page=N) y con búsqueda (?q=).
     * El cálculo (recorrer disco) es costoso, así que se cachea 5 min; la
     * paginación y el filtro operan sobre ese resultado en memoria, sin más
     * consultas. ?refresh=1 fuerza el recálculo.
     */
    public function companies(Request $request, SystemMonitorService $monitor): JsonResponse
    {
        if ($request->boolean('refresh')) {
            Cache::forget('monitoring.company_usage');
        }

        $data = Cache::remember('monitoring.company_usage', now()->addMinutes(5), function () use ($monitor) {
            return $monitor->companyUsage();
        });

        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 50;
        $search  = trim((string) $request->query('q', ''));

        $companies = $data['companies'] ?? [];
        if ($search !== '') {
            $companies = array_values(array_filter($companies, function ($c) use ($search) {
                return stripos((string) $c['nit'], $search) !== false
                    || stripos((string) $c['name'], $search) !== false;
            }));
        }

        $total    = count($companies);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $slice    = array_slice($companies, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'generated_at'  => $data['generated_at'] ?? null,
            'storage_total' => $data['storage_total'] ?? 0, // total de TODAS las empresas
            'companies'     => $slice,
            'page'          => $page,
            'last_page'     => $lastPage,
            'total'         => $total,
        ]);
    }
}
