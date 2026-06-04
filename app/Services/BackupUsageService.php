<?php

namespace App\Services;

use App\Company;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Calcula el peso en disco de los archivos guardados (XML, PDF, ZIP, anexos) por
 * empresa, para el panel de Backups.
 *
 * Pensado para producción con miles de archivos: el cálculo NO recorre archivo
 * por archivo en PHP, sino que usa `du` (una sola llamada por carpeta base, en C)
 * igual que el módulo de monitoreo. El resultado se cachea y se precalcula por
 * cron, de modo que la vista web solo lee de caché y nunca recorre el disco.
 *
 * Carpetas que se miden (relativas a storage/app):
 *   public/{NIT}      received/{NIT}   → indexadas por identification_number
 *   xml/{company_id}  zip/{company_id} → indexadas por id de empresa
 */
class BackupUsageService
{
    /** Cuánto se conserva el cálculo en caché (debe ser mayor que la frecuencia del cron). */
    const CACHE_TTL_MINUTES = 1440; // 24 h

    const CACHE_KEY = 'backups.usage';

    /** Carpetas indexadas por NIT (identification_number). */
    public $dirsByNit = ['public', 'received'];

    /** Carpetas indexadas por id de empresa. */
    public $dirsById = ['xml', 'zip'];

    /**
     * Snapshot ya cacheado, o null si todavía no se ha calculado.
     */
    public function cached(): ?array
    {
        return Cache::get(self::CACHE_KEY);
    }

    /**
     * Devuelve el cálculo. Con $fresh=true lo recalcula y reescribe el caché
     * (lo usa el comando de cron y el botón "Recalcular"). Sin $fresh, si no hay
     * caché lo calcula una vez (primer arranque) y lo guarda.
     */
    public function usage(bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = Cache::get(self::CACHE_KEY);
            if ($cached) {
                return $cached;
            }
        }

        $data = $this->compute();
        Cache::put(self::CACHE_KEY, $data, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return $data;
    }

    /** Limpia el caché para forzar un recálculo en el próximo acceso. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Recorre las carpetas con `du` (o PHP como respaldo) y arma el resumen.
     */
    public function compute(): array
    {
        $companies = $this->safe(function () {
            return Company::with('user:id,name')->get(['id', 'user_id', 'identification_number']);
        }) ?? collect();

        // Peso por carpeta inmediata, en una sola llamada a `du` por carpeta base.
        $useDu = !$this->isWindows();
        $byNit = [];
        $byId  = [];

        if ($useDu) {
            foreach ($this->dirsByNit as $dir) {
                $map = $this->duMap("app/{$dir}");
                if ($map === null) { $useDu = false; break; }
                foreach ($map as $key => $val) {
                    $byNit[$key] = ($byNit[$key] ?? 0) + $val;
                }
            }
        }
        if ($useDu) {
            foreach ($this->dirsById as $dir) {
                $map = $this->duMap("app/{$dir}");
                if ($map === null) { $useDu = false; break; }
                foreach ($map as $key => $val) {
                    $byId[$key] = ($byId[$key] ?? 0) + $val;
                }
            }
        }

        $rows = [];
        $totalBytes = 0;

        foreach ($companies as $company) {
            $nit = (string) $company->identification_number;
            $id  = (string) $company->id;

            $bytes = $useDu
                ? (int) (($byNit[$nit] ?? 0) + ($byId[$id] ?? 0))
                : $this->phpBytes($nit, $id);

            if ($bytes <= 0) {
                continue;
            }

            $totalBytes += $bytes;
            $rows[] = [
                'id'    => (int) $company->id,
                'nit'   => $nit,
                'name'  => optional($company->user)->name ?? '—',
                'bytes' => $bytes,
            ];
        }

        usort($rows, function ($a, $b) {
            return $b['bytes'] <=> $a['bytes'];
        });

        return [
            'companies'    => $rows,
            'totalBytes'   => $totalBytes,
            'companyCount' => count($rows),
            'method'       => $useDu ? 'du' : 'php',
            'generatedAt'  => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Carpetas existentes de una empresa: [categoria => ruta absoluta]. Lo usa el
     * Job para saber qué comprimir y, al liberar espacio, qué borrar.
     *
     * @return array<string,string>
     */
    public function companyDirs(string $nit, string $id): array
    {
        $dirs = [];
        foreach ($this->dirsByNit as $d) {
            $p = storage_path("app/{$d}/{$nit}");
            if (is_dir($p)) {
                $dirs[$d] = $p;
            }
        }
        foreach ($this->dirsById as $d) {
            $p = storage_path("app/{$d}/{$id}");
            if (is_dir($p)) {
                $dirs[$d] = $p;
            }
        }
        return $dirs;
    }

    /**
     * ¿Es el archivo el logo (principal o alterno) de la empresa? El logo se
     * guarda como {NIT}{dv}.jpg o alternate_{NIT}{dv}.jpg, donde dv es el dígito
     * de verificación. Ningún nombre de documento coincide con ese patrón.
     */
    public function isLogoFile(string $nit, string $filename): bool
    {
        return (bool) preg_match(
            '/^(alternate_)?' . preg_quote($nit, '/') . '\d+\.jpg$/i',
            $filename
        );
    }

    /**
     * Vista previa para el modal de una empresa: desglose por año + total y (si se
     * pasa from/to) cuántos archivos/peso coinciden con ese rango.
     *
     * Escalable: usa `find` (en C, una llamada por carpeta) en vez de recorrer
     * archivo por archivo en PHP, así abre rápido aunque la empresa tenga cientos
     * de miles de documentos. El desglose sin rango se cachea 5 min para que
     * reabrir el modal sea instantáneo. Excluye los logos.
     */
    public function previewScan(string $nit, string $id, ?int $from = null, ?int $to = null): array
    {
        $cacheable = ($from === null && $to === null);
        $cacheKey  = 'backups.preview.' . $id;

        if ($cacheable) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return $cached;
            }
        }

        $years = [];
        $totalFiles = 0; $totalBytes = 0;
        $rangeFiles = 0; $rangeBytes = 0;

        foreach ($this->companyDirs($nit, $id) as $category => $absDir) {
            foreach ($this->listDir($absDir) as [$mtime, $size, $name]) {
                if ($category === 'public' && $this->isLogoFile($nit, $name)) {
                    continue;
                }
                $y = (int) date('Y', $mtime);
                if (!isset($years[$y])) {
                    $years[$y] = ['files' => 0, 'bytes' => 0];
                }
                $years[$y]['files']++;
                $years[$y]['bytes'] += $size;
                $totalFiles++;
                $totalBytes += $size;

                if (($from === null || $mtime >= $from) && ($to === null || $mtime <= $to)) {
                    $rangeFiles++;
                    $rangeBytes += $size;
                }
            }
        }

        krsort($years);
        $yearsOut = [];
        foreach ($years as $y => $v) {
            $yearsOut[] = ['year' => $y, 'files' => $v['files'], 'bytes' => $v['bytes']];
        }

        $result = [
            'total' => ['files' => $totalFiles, 'bytes' => $totalBytes],
            'years' => $yearsOut,
        ];
        if (!$cacheable) {
            $result['range'] = ['files' => $rangeFiles, 'bytes' => $rangeBytes];
        }

        if ($cacheable) {
            Cache::put($cacheKey, $result, now()->addMinutes(5));
        }

        return $result;
    }

    /** Invalida la caché de vista previa de una empresa (tras borrar archivos). */
    public function forgetPreview(string $id): void
    {
        Cache::forget('backups.preview.' . $id);
    }

    /**
     * Lista los archivos de una carpeta como [mtime, size, basename] usando `find`
     * (rápido, en C). Si find/shell no está disponible (Windows/dev), recorre en
     * PHP. Generador: no acumula toda la lista en memoria.
     *
     * @return \Generator
     */
    protected function listDir(string $absDir): \Generator
    {
        if (!$this->isWindows()) {
            $out = $this->run('find ' . escapeshellarg($absDir) . " -type f -printf '%T@\\t%s\\t%f\\n' 2>/dev/null");
            if ($out !== null) {
                $line = strtok($out, "\n");
                while ($line !== false) {
                    $parts = explode("\t", $line, 3);
                    if (count($parts) === 3) {
                        yield [(int) $parts[0], (int) $parts[1], $parts[2]];
                    }
                    $line = strtok("\n");
                }
                return;
            }
        }

        // Respaldo en PHP (Windows o sin shell).
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                yield [$file->getMTime(), $file->getSize(), $file->getFilename()];
            }
        }
    }

    /**
     * Recorre los archivos de una empresa (todas sus carpetas), opcionalmente
     * filtrando por fecha de modificación (mtime) entre $from y $to (timestamps
     * unix; null = sin límite). Cada elemento: path, mtime, size, category, entry
     * (ruta dentro del ZIP: "{NIT}/{categoria}/{relativo}").
     *
     * Es un generador: no carga toda la lista en memoria. Lo usan la vista previa,
     * el Job que comprime y el borrado por rango.
     *
     * @return \Generator
     */
    public function mapFiles(string $nit, string $id, ?int $from = null, ?int $to = null): \Generator
    {
        foreach ($this->companyDirs($nit, $id) as $category => $absDir) {
            $base = rtrim($absDir, DIRECTORY_SEPARATOR);
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                // Nunca incluir los logos de la empresa (son configuración para
                // generar PDFs, no documentos). Viven en public/{NIT}/ como
                // {NIT}{dv}.jpg y alternate_{NIT}{dv}.jpg. Así no se respaldan ni
                // se borran: el logo siempre se queda en disco.
                if ($category === 'public' && $this->isLogoFile($nit, $file->getFilename())) {
                    continue;
                }
                $m = $file->getMTime();
                if ($from !== null && $m < $from) {
                    continue;
                }
                if ($to !== null && $m > $to) {
                    continue;
                }
                $rel = ltrim(str_replace($base, '', $file->getPathname()), DIRECTORY_SEPARATOR);
                $rel = str_replace('\\', '/', $rel);
                yield [
                    'path'     => $file->getPathname(),
                    'mtime'    => $m,
                    'size'     => $file->getSize(),
                    'category' => $category,
                    'entry'    => $nit . '/' . $category . '/' . $rel,
                ];
            }
        }
    }

    /**
     * [basename => bytes] del peso de cada subcarpeta inmediata de una carpeta
     * base, con una sola llamada a `du -b --max-depth=1`. [] si no existe; null
     * si `du`/shell no está disponible (para usar el respaldo en PHP).
     *
     * @return array<string,int>|null
     */
    protected function duMap(string $rel): ?array
    {
        $base = storage_path($rel);
        if (!is_dir($base)) {
            return [];
        }

        $out = $this->run('du -b --max-depth=1 ' . escapeshellarg($base) . ' 2>/dev/null');
        if ($out === null) {
            return null;
        }

        $map = [];
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
            if ($path === $base) {
                continue;
            }
            $map[basename($path)] = (int) $parts[0];
        }

        return $map;
    }

    /**
     * Respaldo en PHP: bytes de una empresa sumando sus carpetas. Solo en
     * desarrollo/Windows o si `du` no está disponible.
     */
    protected function phpBytes(string $nit, string $id): int
    {
        return (int) ($this->safe(function () use ($nit, $id) {
            $total = 0;
            foreach ($this->companyDirs($nit, $id) as $dir) {
                $total += $this->dirSize($dir);
            }
            return $total;
        }) ?? 0);
    }

    public function dirSize(string $dir): int
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

    protected function safe(callable $fn)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            return null;
        }
    }
}
