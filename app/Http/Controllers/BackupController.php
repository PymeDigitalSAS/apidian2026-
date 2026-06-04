<?php

namespace App\Http\Controllers;

use App\Backup;
use App\Company;
use App\Jobs\BuildBackup;
use App\Jobs\PurgeFiles;
use App\Purge;
use App\Services\BackupUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Panel de copias de seguridad (backups) — solo para el admin de la plataforma.
 *
 * Flujo:
 *   1. Muestra el peso en disco por empresa (precalculado, ver BackupUsageService).
 *   2. El admin pide un respaldo (de una empresa o de todas), pudiendo filtrar por
 *      año / rango de fechas. Se encola un Job que genera el ZIP en segundo plano.
 *   3. El admin descarga el ZIP.
 *   4. El admin libera espacio borrando los archivos reales del rango elegido
 *      (acción destructiva, con vista previa y doble confirmación).
 *
 * El filtro de fechas se basa en la fecha de modificación del archivo (mtime),
 * que coincide con cuándo se generó/guardó el documento.
 */
class BackupController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');

        // Solo el administrador de la plataforma (sin empresa asociada) puede
        // ver y gestionar los backups: es información sensible y destructiva.
        $this->middleware(function ($request, $next) {
            $user = auth()->user();
            if (!$user || !method_exists($user, 'isPlatformAdmin') || !$user->isPlatformAdmin()) {
                abort(403, 'No autorizado.');
            }
            return $next($request);
        });
    }

    /**
     * Página principal: peso por empresa (de caché) + respaldos generados.
     */
    public function index(Request $request, BackupUsageService $service)
    {
        $usage = $service->usage(false);
        $usage['cacheMinutes'] = BackupUsageService::CACHE_TTL_MINUTES;
        $usage['backups'] = Backup::orderByDesc('id')->limit(50)->get();

        // Espacio en disco (para prevenir respaldos que no caben).
        $base = storage_path();
        $usage['diskFree']  = @disk_free_space($base) ?: null;
        $usage['diskTotal'] = @disk_total_space($base) ?: null;

        return view('backups.index', $usage);
    }

    /**
     * Fuerza el recálculo del peso por empresa (con candado anti-spam) y vuelve.
     */
    public function recalculate(BackupUsageService $service)
    {
        if (Cache::add('backups.refresh.lock', 1, now()->addSeconds(60))) {
            $service->usage(true);
        }
        return redirect()->route('backups.index')->with('status', 'Peso recalculado.');
    }

    /**
     * Vista previa para el modal de una empresa: desglose por año + total, y
     * (si se pasa from/to) cuántos archivos/peso coinciden con ese rango. Hace
     * UN solo recorrido de los archivos de la empresa.
     */
    public function preview(Request $request, $company, BackupUsageService $service)
    {
        // Resolvemos por id (no usamos route-model-binding porque Company tiene
        // getRouteKeyName() = 'identification_number', y aquí pasamos el id).
        $company = Company::findOrFail($company);

        $from = $request->filled('from') ? strtotime($request->input('from') . ' 00:00:00') : null;
        $to   = $request->filled('to')   ? strtotime($request->input('to') . ' 23:59:59')   : null;

        // Escaneo rápido con `find` (en C) + caché; soporta empresas con muchos
        // documentos sin bloquear la petición web.
        return response()->json($service->previewScan(
            (string) $company->identification_number,
            (string) $company->id,
            $from ?: null,
            $to ?: null
        ));
    }

    /**
     * Solicita un respaldo (scope 'all' o 'company'), opcionalmente filtrado por
     * año / rango, y encola su generación.
     */
    public function store(Request $request, BackupUsageService $service)
    {
        $data = $request->validate([
            'scope'      => 'required|in:all,company',
            'company_id' => 'required_if:scope,company|nullable|integer|exists:companies,id',
            'year'       => 'nullable|integer|min:2000|max:2100',
            'from'       => 'nullable|date',
            'to'         => 'nullable|date',
        ]);

        $scope = $data['scope'];
        $company = $scope === 'company' ? Company::find($data['company_id']) : null;

        [$fromTs, $toTs, $label] = $this->resolveFilter($request);

        // Aviso INMEDIATO si claramente no hay espacio (sin encolar nada). Solo
        // para respaldos sin filtro de fecha, usando el peso ya cacheado (no
        // escanea disco en la petición web). Con filtro, lo verifica el Job.
        if ($fromTs === null && $toTs === null) {
            $usage = $service->usage(false);
            $estimated = 0;
            if ($scope === 'all') {
                $estimated = (int) ($usage['totalBytes'] ?? 0);
            } else {
                foreach (($usage['companies'] ?? []) as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) $company->id) {
                        $estimated = (int) $row['bytes'];
                        break;
                    }
                }
            }

            $free = @disk_free_space(storage_path());
            if ($free !== false && $estimated > 0 && $free < $estimated) {
                return redirect()->route('backups.index')->with('error', sprintf(
                    'No hay espacio suficiente en disco para este respaldo: ocupa ~%s y solo hay ~%s libres. '
                    . 'Libera espacio (borra respaldos antiguos o archivos) antes de generarlo.',
                    $this->fmtBytes($estimated),
                    $this->fmtBytes($free)
                ));
            }
        }

        // Evita duplicados en curso para el mismo objetivo.
        $pending = Backup::whereIn('status', ['pending', 'processing'])
            ->where('scope', $scope)
            ->when($scope === 'company', function ($q) use ($company) {
                $q->where('company_id', $company->id);
            })
            ->exists();

        if ($pending) {
            return redirect()->route('backups.index')
                ->with('error', 'Ya hay un respaldo en proceso para ese objetivo. Espera a que termine.');
        }

        // No respaldar mientras se está borrando ese objetivo (archivos en vuelo).
        $purging = Purge::whereIn('status', ['pending', 'processing'])
            ->when($scope === 'company', function ($q) use ($company) {
                $q->where('company_id', $company->id);
            })
            ->exists();

        if ($purging) {
            return redirect()->route('backups.index')
                ->with('error', 'Hay una liberación de espacio en proceso para ese objetivo. Espera a que termine.');
        }

        $backup = Backup::create([
            'scope'        => $scope,
            'company_id'   => $company->id ?? null,
            'nit'          => $company->identification_number ?? null,
            'name'         => $company ? (optional($company->user)->name ?? null) : 'Todas las empresas',
            'filter_from'  => $fromTs,
            'filter_to'    => $toTs,
            'filter_label' => $label,
            'status'       => 'pending',
            'requested_by' => optional(auth()->user())->email ?? optional(auth()->user())->name,
        ]);

        BuildBackup::dispatch($backup->id)->onConnection('database');

        return redirect()->route('backups.index')
            ->with('status', 'Respaldo solicitado (' . $label . '). Se está generando en segundo plano; podrás descargarlo en unos momentos.');
    }

    /**
     * Estado de los respaldos en JSON (para refrescar la tabla sin recargar).
     */
    public function list()
    {
        $backups = Backup::orderByDesc('id')->limit(50)->get()->map(function (Backup $b) {
            return [
                'id'            => $b->id,
                'scope'         => $b->scope,
                'nit'           => $b->nit,
                'name'          => $b->name,
                'label'         => $b->filter_label,
                'status'        => $b->status,
                'size_bytes'    => $b->size_bytes,
                'files_count'   => $b->files_count,
                'error'         => $b->error,
                'downloaded_at' => optional($b->downloaded_at)->format('Y-m-d H:i'),
                'created_at'    => optional($b->created_at)->format('Y-m-d H:i'),
                'can_download'  => $b->isDone() && $b->disk_path && is_file($b->absolutePath()),
            ];
        });

        return response()->json(['backups' => $backups]);
    }

    /**
     * Descarga el ZIP de un respaldo y marca la fecha de descarga.
     */
    public function download(Backup $backup)
    {
        $abs = $backup->absolutePath();
        if (!$backup->isDone() || !$abs || !is_file($abs)) {
            return redirect()->route('backups.index')
                ->with('error', 'El archivo del respaldo no está disponible.');
        }

        if (!$backup->downloaded_at) {
            $backup->update(['downloaded_at' => now()]);
        }

        return response()->download($abs, basename($abs));
    }

    /**
     * Elimina un respaldo (el ZIP en disco y su registro).
     */
    public function destroy(Backup $backup)
    {
        $abs = $backup->absolutePath();
        if ($abs && is_file($abs)) {
            @unlink($abs);
        }
        $backup->delete();

        return redirect()->route('backups.index')->with('status', 'Respaldo eliminado.');
    }

    /**
     * Libera espacio borrando los archivos REALES de una empresa (o de todas),
     * opcionalmente solo los de un año / rango de fechas. Acción irreversible:
     * exige doble confirmación (escribir el código + marcar la casilla).
     *
     * Se ejecuta en segundo plano (Job PurgeFiles) para no bloquear la petición
     * web ni dar timeout con empresas de muchos archivos.
     */
    public function freeSpace(Request $request)
    {
        $data = $request->validate([
            'scope'        => 'required|in:all,company',
            'company_id'   => 'required_if:scope,company|nullable|integer|exists:companies,id',
            'year'         => 'nullable|integer|min:2000|max:2100',
            'from'         => 'nullable|date',
            'to'           => 'nullable|date',
            'confirm_text' => 'required|string',
            'confirm_ack'  => 'accepted',
        ]);

        $scope = $data['scope'];
        $company = $scope === 'company' ? Company::findOrFail($data['company_id']) : null;
        $expected = $scope === 'all' ? 'ELIMINAR TODO' : (string) $company->identification_number;

        if (trim($data['confirm_text']) !== $expected) {
            return redirect()->route('backups.index')
                ->with('error', "Confirmación incorrecta: debías escribir exactamente «{$expected}». No se borró nada.");
        }

        // No borrar mientras haya un respaldo en curso del mismo objetivo (el Job
        // podría estar leyendo esos archivos), ni si ya hay otro borrado en curso.
        $busyBackup = Backup::whereIn('status', ['pending', 'processing'])
            ->when($scope === 'company', fn ($q) => $q->where('company_id', $company->id))
            ->exists();
        $busyPurge = Purge::whereIn('status', ['pending', 'processing'])
            ->when($scope === 'company', fn ($q) => $q->where('company_id', $company->id))
            ->exists();

        if ($busyBackup || $busyPurge) {
            return redirect()->route('backups.index')
                ->with('error', 'Hay una operación en proceso para ese objetivo. Espera a que termine.');
        }

        [$fromTs, $toTs, $label] = $this->resolveFilter($request);

        $purge = Purge::create([
            'scope'        => $scope,
            'company_id'   => $company->id ?? null,
            'nit'          => $company->identification_number ?? null,
            'name'         => $company ? (optional($company->user)->name ?? null) : 'Todas las empresas',
            'filter_from'  => $fromTs,
            'filter_to'    => $toTs,
            'filter_label' => $label,
            'status'       => 'pending',
            'requested_by' => optional(auth()->user())->email ?? optional(auth()->user())->name,
        ]);

        PurgeFiles::dispatch($purge->id)->onConnection('database');

        return redirect()->route('backups.index')->with('status',
            'Borrado solicitado (' . $label . '). Se está procesando en segundo plano; el espacio se liberará en unos momentos.');
    }

    /**
     * Estado de las liberaciones de espacio en JSON (para refrescar sin recargar).
     */
    public function purges()
    {
        $purges = Purge::orderByDesc('id')->limit(50)->get()->map(function (Purge $p) {
            return [
                'id'            => $p->id,
                'scope'         => $p->scope,
                'nit'           => $p->nit,
                'name'          => $p->name,
                'label'         => $p->filter_label,
                'status'        => $p->status,
                'deleted_files' => $p->deleted_files,
                'freed_bytes'   => $p->freed_bytes,
                'error'         => $p->error,
                'created_at'    => optional($p->created_at)->format('Y-m-d H:i'),
            ];
        });

        return response()->json(['purges' => $purges]);
    }

    /* --------------------------------------------------------------------- */
    /* Utilidades                                                            */
    /* --------------------------------------------------------------------- */

    /**
     * Resuelve el filtro de fechas del request a [from_ts, to_ts, label].
     * Prioridad: rango (from/to) > año > todo.
     *
     * @return array{0:?int,1:?int,2:string}
     */
    protected function resolveFilter(Request $request): array
    {
        $year = $request->input('year');
        $from = $request->input('from');
        $to   = $request->input('to');

        if ($from || $to) {
            $fromTs = $from ? strtotime($from . ' 00:00:00') : null;
            $toTs   = $to   ? strtotime($to . ' 23:59:59')   : null;
            $label  = 'Rango ' . ($from ?: '…') . ' → ' . ($to ?: '…');
            return [$fromTs ?: null, $toTs ?: null, $label];
        }

        if ($year && ctype_digit((string) $year)) {
            $y = (int) $year;
            return [mktime(0, 0, 0, 1, 1, $y), mktime(23, 59, 59, 12, 31, $y), 'Año ' . $y];
        }

        return [null, null, 'Todo'];
    }

    protected function fmtBytes($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0; $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
        return number_format($n, ($n >= 100 || $i === 0) ? 0 : 1) . ' ' . $units[$i];
    }
}
