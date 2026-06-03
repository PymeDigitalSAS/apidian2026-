<?php

namespace App\Services;

use App\Company;
use Illuminate\Support\Facades\DB;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Recolecta métricas de recursos del servidor (CPU, memoria y almacenamiento)
 * de forma tolerante a fallos, y calcula el consumo de cada empresa en base de
 * datos y en disco. Optimizado para Linux/Docker con fallback en Windows.
 *
 * Ningún recolector debe lanzar una excepción hacia afuera: si una métrica no se
 * puede leer, se devuelve null para esa métrica y el resto sigue funcionando.
 */
class SystemMonitorService
{
    /**
     * Recolecta una instantánea de los recursos del sistema.
     *
     * @return array
     */
    public function collect(): array
    {
        $cpu    = $this->cpu();
        $memory = $this->memory();
        $disk   = $this->disk();

        return [
            'cpu_percent'  => $cpu['percent'],
            'load_1'       => $cpu['load_1'],
            'load_5'       => $cpu['load_5'],
            'load_15'      => $cpu['load_15'],
            'cpu_cores'    => $cpu['cores'],

            'mem_total'    => $memory['total'],
            'mem_used'     => $memory['used'],
            'mem_percent'  => $memory['percent'],

            'disk_total'   => $disk['total'],
            'disk_used'    => $disk['used'],
            'disk_percent' => $disk['percent'],

            'hostname'     => $this->safe(fn () => gethostname() ?: null),
            'environment'  => $this->detectEnvironment(),
        ];
    }

    /* --------------------------------------------------------------------- */
    /* CPU / carga                                                           */
    /* --------------------------------------------------------------------- */

    protected function cpu(): array
    {
        $cores = $this->cpuCores();
        $load  = $this->safe(fn () => function_exists('sys_getloadavg') ? sys_getloadavg() : null);

        $result = [
            'percent' => null,
            'load_1'  => null,
            'load_5'  => null,
            'load_15' => null,
            'cores'   => $cores,
        ];

        if (is_array($load) && count($load) >= 3) {
            $result['load_1']  = round((float) $load[0], 2);
            $result['load_5']  = round((float) $load[1], 2);
            $result['load_15'] = round((float) $load[2], 2);

            if ($cores > 0) {
                $result['percent'] = min(100.0, round(($load[0] / $cores) * 100, 1));
            }
        }

        // En Linux, medición directa por muestreo de /proc/stat (más exacta).
        $sampled = $this->cpuPercentFromProcStat();
        if ($sampled !== null) {
            $result['percent'] = $sampled;
        } elseif ($result['percent'] === null && $this->isWindows()) {
            $result['percent'] = $this->cpuPercentWindows();
        }

        return $result;
    }

    protected function cpuCores(): ?int
    {
        return $this->safe(function () {
            if (is_readable('/proc/cpuinfo')) {
                $count = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
                if ($count > 0) {
                    return $count;
                }
            }
            if ($this->isWindows() && getenv('NUMBER_OF_PROCESSORS')) {
                return (int) getenv('NUMBER_OF_PROCESSORS');
            }
            return null;
        });
    }

    /**
     * Mide el uso de CPU tomando dos muestras de /proc/stat separadas por un
     * intervalo corto. Devuelve null si no está disponible (no Linux).
     */
    protected function cpuPercentFromProcStat(): ?float
    {
        return $this->safe(function () {
            if (!is_readable('/proc/stat')) {
                return null;
            }
            $read = function () {
                $line = strtok((string) @file_get_contents('/proc/stat'), "\n");
                if (!preg_match('/^cpu\s+(.*)$/', $line, $m)) {
                    return null;
                }
                $parts = preg_split('/\s+/', trim($m[1]));
                $parts = array_map('intval', $parts);
                $idle  = ($parts[3] ?? 0) + ($parts[4] ?? 0); // idle + iowait
                $total = array_sum($parts);
                return ['idle' => $idle, 'total' => $total];
            };

            $a = $read();
            if ($a === null) {
                return null;
            }
            usleep(200000); // 200 ms
            $b = $read();
            if ($b === null) {
                return null;
            }

            $totalDiff = $b['total'] - $a['total'];
            $idleDiff  = $b['idle'] - $a['idle'];
            if ($totalDiff <= 0) {
                return null;
            }
            return round((1 - $idleDiff / $totalDiff) * 100, 1);
        });
    }

    protected function cpuPercentWindows(): ?float
    {
        return $this->safe(function () {
            $out = $this->run('wmic cpu get loadpercentage /value');
            if ($out && preg_match('/LoadPercentage=(\d+)/i', $out, $m)) {
                return (float) $m[1];
            }
            return null;
        });
    }

    /* --------------------------------------------------------------------- */
    /* Memoria                                                               */
    /* --------------------------------------------------------------------- */

    protected function memory(): array
    {
        $result = ['total' => null, 'used' => null, 'percent' => null];

        // 1) Límite de cgroup (contenedor Docker) si existe.
        $cgroup = $this->memoryFromCgroup();
        if ($cgroup !== null) {
            return $cgroup;
        }

        // 2) /proc/meminfo (Linux host).
        $meminfo = $this->safe(function () {
            if (!is_readable('/proc/meminfo')) {
                return null;
            }
            $data = (string) @file_get_contents('/proc/meminfo');
            $get = function ($key) use ($data) {
                if (preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\s+kB/m', $data, $m)) {
                    return (int) $m[1] * 1024; // kB -> bytes
                }
                return null;
            };
            $total     = $get('MemTotal');
            $available = $get('MemAvailable');
            if ($total === null) {
                return null;
            }
            if ($available === null) {
                $free    = (int) ($get('MemFree') ?? 0);
                $buffers = (int) ($get('Buffers') ?? 0);
                $cached  = (int) ($get('Cached') ?? 0);
                $available = $free + $buffers + $cached;
            }
            $used = max(0, $total - $available);
            return [
                'total'   => $total,
                'used'    => $used,
                'percent' => $total > 0 ? round($used / $total * 100, 1) : null,
            ];
        });
        if ($meminfo !== null) {
            return $meminfo;
        }

        // 3) Windows (desarrollo).
        if ($this->isWindows()) {
            $win = $this->safe(function () {
                $out = $this->run('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /value');
                if ($out
                    && preg_match('/FreePhysicalMemory=(\d+)/i', $out, $f)
                    && preg_match('/TotalVisibleMemorySize=(\d+)/i', $out, $t)) {
                    $total = (int) $t[1] * 1024; // kB -> bytes
                    $free  = (int) $f[1] * 1024;
                    $used  = max(0, $total - $free);
                    return [
                        'total'   => $total,
                        'used'    => $used,
                        'percent' => $total > 0 ? round($used / $total * 100, 1) : null,
                    ];
                }
                return null;
            });
            if ($win !== null) {
                return $win;
            }
        }

        return $result;
    }

    protected function memoryFromCgroup(): ?array
    {
        return $this->safe(function () {
            // cgroup v2
            $maxV2     = '/sys/fs/cgroup/memory.max';
            $currentV2 = '/sys/fs/cgroup/memory.current';
            if (is_readable($maxV2) && is_readable($currentV2)) {
                $max = trim((string) @file_get_contents($maxV2));
                if ($max !== 'max' && (int) $max > 0) {
                    $total = (int) $max;
                    $used  = (int) trim((string) @file_get_contents($currentV2));
                    return [
                        'total'   => $total,
                        'used'    => $used,
                        'percent' => $total > 0 ? round($used / $total * 100, 1) : null,
                    ];
                }
            }

            // cgroup v1
            $maxV1   = '/sys/fs/cgroup/memory/memory.limit_in_bytes';
            $usageV1 = '/sys/fs/cgroup/memory/memory.usage_in_bytes';
            if (is_readable($maxV1) && is_readable($usageV1)) {
                $total = (int) trim((string) @file_get_contents($maxV1));
                // Un límite gigantesco (~ sin límite) no es útil: lo ignoramos.
                if ($total > 0 && $total < 8 * (1024 ** 4)) {
                    $used = (int) trim((string) @file_get_contents($usageV1));
                    return [
                        'total'   => $total,
                        'used'    => $used,
                        'percent' => $total > 0 ? round($used / $total * 100, 1) : null,
                    ];
                }
            }

            return null;
        });
    }

    /* --------------------------------------------------------------------- */
    /* Disco / almacenamiento                                                */
    /* --------------------------------------------------------------------- */

    protected function disk(): array
    {
        return $this->safe(function () {
            $path  = base_path();
            $total = @disk_total_space($path);
            $free  = @disk_free_space($path);
            if ($total === false || $free === false || $total === null) {
                return ['total' => null, 'used' => null, 'percent' => null];
            }
            $used = max(0, $total - $free);
            return [
                'total'   => (int) $total,
                'used'    => (int) $used,
                'percent' => $total > 0 ? round($used / $total * 100, 1) : null,
            ];
        }) ?? ['total' => null, 'used' => null, 'percent' => null];
    }

    /* --------------------------------------------------------------------- */
    /* Consultas activas (para capturar qué satura el CPU)                   */
    /* --------------------------------------------------------------------- */

    /**
     * Devuelve las consultas SQL que se están ejecutando en este instante
     * (no en estado Sleep), ordenadas por tiempo de ejecución descendente.
     * Se captura cuando el CPU se satura para saber qué la está saturando.
     *
     * @return array
     */
    public function activeQueries(int $limit = 20): array
    {
        return $this->safe(function () use ($limit) {
            $rows = DB::select('SHOW FULL PROCESSLIST');
            $queries = [];
            foreach ($rows as $row) {
                $command = $row->Command ?? '';
                $info    = trim((string) ($row->Info ?? ''));
                // Ignorar conexiones inactivas y la propia consulta de monitoreo.
                if (strcasecmp($command, 'Sleep') === 0 || $info === '' ||
                    stripos($info, 'SHOW FULL PROCESSLIST') !== false) {
                    continue;
                }
                $queries[] = [
                    'id'      => $row->Id ?? null,
                    'user'    => $row->User ?? null,
                    'db'      => $row->db ?? null,
                    'time'    => isset($row->Time) ? (int) $row->Time : null,
                    'state'   => $row->State ?? null,
                    'query'   => mb_substr($info, 0, 1000),
                ];
            }
            usort($queries, fn ($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));
            return array_slice($queries, 0, $limit);
        }) ?? [];
    }

    /**
     * Devuelve los procesos del SO que más CPU/memoria consumen en este instante.
     * En Linux usa `ps`; en Windows `tasklist`. En Docker muestra los procesos del
     * contenedor (o los del host si el contenedor tiene `pid: host`).
     *
     * @return array
     */
    public function topProcesses(int $limit = 15): array
    {
        return $this->safe(function () use ($limit) {
            return $this->isWindows()
                ? $this->topProcessesWindows($limit)
                : $this->topProcessesLinux($limit);
        }) ?? [];
    }

    protected function topProcessesLinux(int $limit): array
    {
        $out = $this->run('ps -eo pid,user:20,%cpu,%mem,rss,etime,comm,args --sort=-%cpu --no-headers 2>/dev/null');
        if (!$out) {
            return [];
        }
        $procs = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 8);
            if (count($parts) < 8) {
                continue;
            }
            $procs[] = [
                'pid'     => (int) $parts[0],
                'user'    => $parts[1],
                'cpu'     => (float) $parts[2],
                'mem'     => (float) $parts[3],
                'rss'     => (int) $parts[4] * 1024,
                'etime'   => $parts[5],
                'name'    => $parts[6],
                'command' => mb_substr(trim($parts[7]), 0, 300),
            ];
            if (count($procs) >= $limit) {
                break;
            }
        }
        return $procs;
    }

    protected function topProcessesWindows(int $limit): array
    {
        $out = $this->run('tasklist /v /fo csv /nh');
        if (!$out) {
            return [];
        }
        $procs = [];
        foreach (preg_split('/\r?\n/', trim($out)) as $line) {
            $cols = str_getcsv($line);
            if (count($cols) < 5) {
                continue;
            }
            $memKb = (int) preg_replace('/[^\d]/', '', $cols[4]);
            $procs[] = [
                'pid'     => (int) $cols[1],
                'user'    => $cols[6] ?? null,
                'cpu'     => null,
                'mem'     => null,
                'rss'     => $memKb * 1024,
                'etime'   => $cols[7] ?? null,
                'name'    => $cols[0],
                'command' => $cols[0],
            ];
        }
        usort($procs, fn ($a, $b) => ($b['rss'] ?? 0) <=> ($a['rss'] ?? 0));
        return array_slice($procs, 0, $limit);
    }

    /* --------------------------------------------------------------------- */
    /* Consumo por empresa (storage)                                         */
    /* --------------------------------------------------------------------- */

    /**
     * Calcula, por empresa, el espacio real ocupado en disco por sus archivos.
     * Usa `du` (una sola llamada) en Linux; recorrido en PHP como respaldo.
     *
     * @return array{generated_at:string, storage_total:int, companies:array}
     */
    public function companyUsage(): array
    {
        $companies = $this->safe(function () {
            return Company::with('user:id,name')
                ->get(['id', 'user_id', 'identification_number']);
        }) ?? collect();

        // En Linux usamos `du` (una sola llamada para todas las carpetas, en C);
        // si no está disponible (Windows o shell deshabilitado) recorremos en PHP.
        $duSizes = $this->storageSizesViaDu();

        $rows = [];
        $storageTotal = 0;

        foreach ($companies as $company) {
            $nit = (string) $company->identification_number;

            $storageBytes = $duSizes !== null
                ? (int) ($duSizes[$nit] ?? 0)
                : $this->companyStorageBytes($nit);
            $storageTotal += $storageBytes;

            $rows[] = [
                'nit'           => $nit,
                'name'          => optional($company->user)->name ?? '—',
                'storage_bytes' => $storageBytes,
            ];
        }

        // Orden descendente por consumo.
        usort($rows, fn ($a, $b) => $b['storage_bytes'] <=> $a['storage_bytes']);

        return [
            'generated_at'  => now()->toDateTimeString(),
            'storage_total' => $storageTotal,
            'companies'     => $rows,
        ];
    }

    /**
     * Obtiene el peso de las carpetas de TODAS las empresas con una sola llamada
     * a `du` (rápido, en C). Devuelve [nit => bytes], o null si `du`/shell no
     * están disponibles (Windows o shell_exec deshabilitado) para usar el
     * recorrido en PHP como respaldo.
     *
     * @return array<string,int>|null
     */
    protected function storageSizesViaDu(): ?array
    {
        if ($this->isWindows()) {
            return null;
        }

        $sizes = null;
        foreach (['app/public', 'app/received'] as $rel) {
            $base = storage_path($rel);
            if (!is_dir($base)) {
                continue;
            }

            // du -b --max-depth=1: una sola llamada que lista el peso (en bytes)
            // de cada subcarpeta inmediata, sin expandir un glob en la línea de
            // comandos (seguro aunque haya miles de empresas). Salida: "bytes\truta".
            $out = $this->run('du -b --max-depth=1 ' . escapeshellarg($base) . ' 2>/dev/null');
            if ($out === null) {
                // shell_exec no disponible: caer al recorrido en PHP.
                return null;
            }

            $sizes = $sizes ?? [];
            foreach (preg_split('/\r?\n/', trim($out)) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parts = preg_split('/\s+/', $line, 2);
                if (count($parts) < 2 || !is_numeric($parts[0])) {
                    continue;
                }
                $path = trim($parts[1]);
                // Saltar la línea del total del directorio padre (public/received).
                if ($path === $base) {
                    continue;
                }
                $nit = basename($path);
                $sizes[$nit] = ($sizes[$nit] ?? 0) + (int) $parts[0];
            }
        }

        return $sizes;
    }

    /**
     * Bytes ocupados por los archivos de una empresa en disco (recorrido en PHP).
     * Respaldo cuando `du` no está disponible. Los archivos se almacenan en
     * storage/app/public/{NIT}/ y storage/app/received/{NIT}/.
     */
    protected function companyStorageBytes(string $nit): int
    {
        return (int) ($this->safe(function () use ($nit) {
            $total = 0;
            foreach (['app/public/' . $nit, 'app/received/' . $nit] as $rel) {
                $dir = storage_path($rel);
                if (is_dir($dir)) {
                    $total += $this->dirSize($dir);
                }
            }
            return $total;
        }) ?? 0);
    }

    protected function dirSize(string $dir): int
    {
        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }

    /* --------------------------------------------------------------------- */
    /* Utilidades                                                            */
    /* --------------------------------------------------------------------- */

    protected function detectEnvironment(): string
    {
        if (is_file('/.dockerenv')) {
            return 'docker';
        }
        $cgroup = @file_get_contents('/proc/1/cgroup');
        if ($cgroup && (stripos($cgroup, 'docker') !== false || stripos($cgroup, 'kubepods') !== false)) {
            return 'docker';
        }
        if ($this->isWindows()) {
            return 'windows';
        }
        $sapi = php_sapi_name();
        if (stripos($sapi, 'fpm') !== false) {
            return 'php-fpm';
        }
        if (stripos($sapi, 'apache') !== false) {
            return 'apache';
        }
        return $sapi ?: 'linux';
    }

    protected function isWindows(): bool
    {
        return stripos(PHP_OS, 'WIN') === 0;
    }

    /**
     * Ejecuta un comando del sistema de forma segura. Devuelve null si las
     * funciones de ejecución están deshabilitadas o el comando falla.
     */
    protected function run(string $command): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true)) {
            return null;
        }
        try {
            $out = @shell_exec($command);
            return $out === null ? null : (string) $out;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Ejecuta un callback y captura cualquier excepción devolviendo null.
     */
    protected function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            return null;
        }
    }
}
