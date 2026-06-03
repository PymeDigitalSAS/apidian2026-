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
     * @return array{rows:array, error:?string}
     */
    public function activeQueries(int $limit = 20): array
    {
        try {
            $rows = DB::select('SHOW FULL PROCESSLIST');
        } catch (Throwable $e) {
            return ['rows' => [], 'error' => $this->humanizeDbError($e)];
        }

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

        // Si no vimos ninguna consulta, distinguir entre "no había ninguna
        // corriendo" (saturación ajena a la BD) y "no las podemos ver". Solo
        // avisamos del privilegio si CONFIRMAMOS que falta, no por heurística.
        $note = null;
        if (empty($queries) && $this->dbMissingProcessPrivilege()) {
            $dbUser = (string) config('database.connections.' . config('database.default') . '.username');
            $target = $dbUser !== '' ? "'{$dbUser}'@'%'" : "'<usuario>'@'%'";
            $note = 'El usuario de la base de datos' . ($dbUser !== '' ? " ({$dbUser})" : '') . ' no tiene '
                  . 'el privilegio global PROCESS (es de nivel servidor, *.*; tener ALL sobre la base propia '
                  . 'no lo incluye), por lo que solo ve sus propias conexiones y no las del resto del sistema. '
                  . "Otorga (como root): GRANT PROCESS ON *.* TO {$target}; FLUSH PRIVILEGES;";
        }

        return ['rows' => array_slice($queries, 0, $limit), 'error' => $note];
    }

    /**
     * Comprueba si al usuario actual le FALTA el privilegio global PROCESS,
     * necesario para ver en SHOW PROCESSLIST las consultas de otras conexiones.
     * PROCESS solo existe a nivel de servidor (ON *.*); tener ALL sobre la base
     * propia no lo incluye. Devuelve false si no se puede determinar, para no
     * avisar en falso.
     */
    protected function dbMissingProcessPrivilege(): bool
    {
        try {
            $grants = DB::select('SHOW GRANTS FOR CURRENT_USER()');
        } catch (Throwable $e) {
            return false; // no podemos verificar: mejor no afirmar nada
        }
        foreach ($grants as $row) {
            $line = strtoupper(implode(' ', (array) $row));
            if (strpos($line, 'ON *.*') !== false
                && (strpos($line, 'ALL PRIVILEGES') !== false || preg_match('/\bPROCESS\b/', $line))) {
                return false; // sí lo tiene (PROCESS explícito o ALL PRIVILEGES global)
            }
        }
        return true; // confirmado: el grant global no incluye PROCESS
    }

    /**
     * Traduce una excepción de base de datos a un mensaje entendible para el panel.
     */
    protected function humanizeDbError(Throwable $e): string
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'PROCESS privilege') !== false || stripos($msg, 'Access denied') !== false) {
            return 'El usuario de la base de datos no tiene permisos suficientes '
                 . '(se requiere el privilegio PROCESS para ver las consultas activas).';
        }
        if (stripos($msg, 'Connection refused') !== false || stripos($msg, 'getaddrinfo') !== false
            || stripos($msg, "Can't connect") !== false || stripos($msg, 'No such host') !== false) {
            return 'No se pudo conectar a la base de datos desde el monitoreo '
                 . '(revisa DB_HOST/DB_PORT y que sean accesibles desde este contexto).';
        }
        return 'No se pudieron leer las consultas activas: ' . mb_substr($msg, 0, 200);
    }

    /**
     * Devuelve los procesos del SO que más CPU/memoria consumen en este instante.
     * En Linux los lee directamente de /proc (sin depender de `ps` ni de
     * shell_exec); con `pid: host` en el contenedor son los procesos del VPS.
     * En Windows usa `tasklist`.
     *
     * @return array{rows:array, error:?string}
     */
    public function topProcesses(int $limit = 15): array
    {
        if ($this->isWindows()) {
            $rows = $this->safe(fn () => $this->topProcessesWindows($limit)) ?? [];
            return ['rows' => $rows, 'error' => $rows ? null : 'No se pudieron leer los procesos (tasklist no disponible).'];
        }

        // Lectura directa de /proc (no necesita procps ni shell_exec).
        $rows = $this->safe(fn () => $this->topProcessesFromProc($limit));
        if (is_array($rows) && count($rows) > 0) {
            return ['rows' => $rows, 'error' => null];
        }

        // Nada disponible: explicar el motivo.
        $base  = $this->procBase();
        $error = !is_readable($base)
            ? "No se pudo leer {$base} en este entorno."
            : 'No se pudieron leer los procesos del sistema. Si corre en Docker, el '
              . 'contenedor solo ve sus propios procesos: usa pid: "host" en el servicio, '
              . 'o monta el /proc del host (RO) y apunta MONITOR_PROC_PATH a esa ruta.';

        return ['rows' => [], 'error' => $error];
    }

    /**
     * Lee los procesos directamente de /proc midiendo el %CPU instantáneo con dos
     * muestras de utime+stime separadas por un intervalo corto (estilo `top`).
     * Devuelve la lista ordenada por consumo de CPU. No usa shell_exec.
     */
    protected function topProcessesFromProc(int $limit): array
    {
        if (!is_readable($this->procBase())) {
            return [];
        }

        $clkTck   = $this->clockTicks();
        $pageSize = 4096; // tamaño de página estándar en x86_64
        $cores    = $this->cpuCores() ?: 1;
        $memTotal = (int) ($this->memory()['total'] ?? 0);
        $uptime   = $this->systemUptime();
        $userMap  = $this->procUserMap();

        $pids = $this->scanPids();
        if (empty($pids)) {
            return [];
        }

        // Muestra A
        $totalA = $this->readTotalJiffies();
        $a = [];
        foreach ($pids as $pid) {
            $s = $this->readProcPidStat($pid);
            if ($s !== null) {
                $a[$pid] = $s;
            }
        }

        usleep(300000); // 300 ms

        // Muestra B
        $totalB = $this->readTotalJiffies();
        $totalDelta = ($totalA !== null && $totalB !== null) ? ($totalB - $totalA) : 0;

        $procs = [];
        foreach ($a as $pid => $sa) {
            $sb = $this->readProcPidStat($pid);
            if ($sb === null) {
                continue; // el proceso terminó entre muestras
            }

            $procDelta = ($sb['utime'] + $sb['stime']) - ($sa['utime'] + $sa['stime']);
            $cpu = $totalDelta > 0 ? round($procDelta / $totalDelta * $cores * 100, 1) : 0.0;

            $rssBytes = $sb['rss_pages'] * $pageSize;
            $mem = $memTotal > 0 ? round($rssBytes / $memTotal * 100, 1) : null;

            $procs[] = [
                'pid'     => $pid,
                'user'    => $userMap[$sb['uid']] ?? (string) $sb['uid'],
                'cpu'     => $cpu,
                'mem'     => $mem,
                'rss'     => $rssBytes,
                'etime'   => $this->formatEtime($uptime, $sb['starttime'], $clkTck),
                'name'    => $sb['comm'],
                'command' => mb_substr($this->readProcCmdline($pid) ?: $sb['comm'], 0, 300),
            ];
        }

        // Orden por CPU; desempate por memoria residente.
        usort($procs, function ($x, $y) {
            return [$y['cpu'], $y['rss']] <=> [$x['cpu'], $x['rss']];
        });

        return array_slice($procs, 0, $limit);
    }

    /**
     * Ruta base del /proc a leer. Por defecto el del propio entorno (/proc); si
     * el monitoreo corre en un contenedor aislado, se puede montar el /proc del
     * host (RO) y apuntar aquí con MONITOR_PROC_PATH (p. ej. /host/proc) para ver
     * los procesos del VPS sin compartir el namespace de PID.
     */
    protected function procBase(): string
    {
        $path = rtrim((string) config('monitoring.proc_path', '/proc'), '/');
        return $path !== '' ? $path : '/proc';
    }

    /** Lista los PID actuales (directorios numéricos de /proc). */
    protected function scanPids(): array
    {
        $entries = @scandir($this->procBase());
        if ($entries === false) {
            return [];
        }
        $pids = [];
        foreach ($entries as $e) {
            if (ctype_digit($e)) {
                $pids[] = (int) $e;
            }
        }
        return $pids;
    }

    /** Suma total de jiffies de la línea agregada `cpu` de /proc/stat. */
    protected function readTotalJiffies(): ?int
    {
        $file = $this->procBase() . '/stat';
        if (!is_readable($file)) {
            return null;
        }
        $line = strtok((string) @file_get_contents($file), "\n");
        if (!is_string($line) || !preg_match('/^cpu\s+(.*)$/', $line, $m)) {
            return null;
        }
        return array_sum(array_map('intval', preg_split('/\s+/', trim($m[1]))));
    }

    /**
     * Lee los campos relevantes de /proc/{pid}/stat. El campo `comm` puede
     * contener espacios y paréntesis, así que se parte por el último ')'.
     *
     * @return array{utime:int,stime:int,starttime:int,rss_pages:int,comm:string,uid:int}|null
     */
    protected function readProcPidStat(int $pid): ?array
    {
        $file = $this->procBase() . "/{$pid}/stat";
        $content = @file_get_contents($file);
        if ($content === false || $content === '') {
            return null;
        }
        $rparen = strrpos($content, ')');
        if ($rparen === false) {
            return null;
        }
        $comm = '';
        $lparen = strpos($content, '(');
        if ($lparen !== false && $rparen > $lparen) {
            $comm = substr($content, $lparen + 1, $rparen - $lparen - 1);
        }
        // Tras el ')', el primer token es el campo 3 (state); el campo N es t[N-3].
        $t = preg_split('/\s+/', trim(substr($content, $rparen + 1)));

        return [
            'utime'     => (int) ($t[11] ?? 0), // campo 14
            'stime'     => (int) ($t[12] ?? 0), // campo 15
            'starttime' => (int) ($t[19] ?? 0), // campo 22
            'rss_pages' => (int) ($t[21] ?? 0), // campo 24
            'comm'      => $comm,
            'uid'       => (int) (@fileowner($file) ?: 0),
        ];
    }

    /** Línea de comandos completa de un proceso (argumentos separados por \0). */
    protected function readProcCmdline(int $pid): string
    {
        $raw = @file_get_contents($this->procBase() . "/{$pid}/cmdline");
        if ($raw === false || $raw === '') {
            return '';
        }
        return trim(str_replace("\0", ' ', $raw));
    }

    /** Mapa uid => nombre de usuario, leído de /etc/passwd. */
    protected function procUserMap(): array
    {
        return $this->safe(function () {
            $map = [];
            if (is_readable('/etc/passwd')) {
                foreach (preg_split('/\r?\n/', (string) @file_get_contents('/etc/passwd')) as $line) {
                    $f = explode(':', $line);
                    if (count($f) >= 3 && ctype_digit($f[2])) {
                        $map[(int) $f[2]] = $f[0];
                    }
                }
            }
            return $map;
        }) ?? [];
    }

    /** Ticks de reloj por segundo (para convertir jiffies a tiempo). */
    protected function clockTicks(): int
    {
        if (function_exists('posix_sysconf') && defined('POSIX_SC_CLK_TCK')) {
            $v = @posix_sysconf(POSIX_SC_CLK_TCK);
            if ($v > 0) {
                return (int) $v;
            }
        }
        return 100; // valor habitual en Linux
    }

    /** Segundos de actividad del sistema, leídos de /proc/uptime. */
    protected function systemUptime(): float
    {
        $raw = @file_get_contents($this->procBase() . '/uptime');
        if ($raw === false) {
            return 0.0;
        }
        return (float) strtok($raw, ' ');
    }

    /** Formatea el tiempo transcurrido de un proceso como [Dd]HH:MM:SS. */
    protected function formatEtime(float $uptime, int $starttime, int $clkTck): string
    {
        if ($uptime <= 0 || $clkTck <= 0) {
            return '—';
        }
        $secs = (int) max(0, $uptime - $starttime / $clkTck);
        $d = intdiv($secs, 86400);
        $h = intdiv($secs % 86400, 3600);
        $m = intdiv($secs % 3600, 60);
        $s = $secs % 60;
        return ($d > 0 ? "{$d}d " : '') . sprintf('%02d:%02d:%02d', $h, $m, $s);
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
