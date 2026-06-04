<?php

namespace App\Jobs;

use App\Company;
use App\Purge;
use App\Services\BackupUsageService;
use FilesystemIterator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Borra en segundo plano los archivos reales de una empresa (o de todas),
 * opcionalmente filtrados por fecha. Acción irreversible. Corre en la cola
 * 'database' (worker) para no bloquear la petición web ni dar timeout con
 * empresas de muchos archivos. Nunca toca los logos (los excluye mapFiles).
 */
class PurgeFiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600;
    public $tries = 1;

    public function __construct(public int $purgeId)
    {
    }

    public function handle(BackupUsageService $usage): void
    {
        $purge = Purge::find($this->purgeId);
        if (!$purge) {
            return;
        }

        $purge->update(['status' => 'processing', 'error' => null]);

        try {
            $targets = $purge->scope === 'all'
                ? Company::get(['id', 'identification_number'])
                : Company::where('id', $purge->company_id)->get(['id', 'identification_number']);

            $from = $purge->filter_from;
            $to   = $purge->filter_to;

            $deletedFiles = 0;
            $freedBytes = 0;

            // Por empresa: recolecta sus archivos (acota memoria aunque sea
            // "todas"), los borra, y limpia carpetas vacías. No se borra durante
            // el recorrido del directorio.
            foreach ($targets as $company) {
                $nit = (string) $company->identification_number;
                $cid = (string) $company->id;

                $items = [];
                foreach ($usage->mapFiles($nit, $cid, $from, $to) as $f) {
                    $items[] = ['path' => $f['path'], 'size' => $f['size']];
                }
                foreach ($items as $it) {
                    if (@unlink($it['path'])) {
                        $deletedFiles++;
                        $freedBytes += $it['size'];
                    }
                }
                foreach ($usage->companyDirs($nit, $cid) as $dir) {
                    $this->pruneEmptyDirs($dir);
                }

                $usage->forgetPreview($cid);
            }

            // El peso cambió: invalidar el caché del dashboard.
            $usage->forget();

            $purge->update([
                'status'        => 'done',
                'deleted_files' => $deletedFiles,
                'freed_bytes'   => $freedBytes,
            ]);

            // Auditoría: el borrado de documentos fiscales debe quedar registrado.
            Log::warning('Backups: liberación de espacio completada', [
                'purge_id'      => $purge->id,
                'user'          => $purge->requested_by,
                'scope'         => $purge->scope,
                'company_id'    => $purge->company_id,
                'filter'        => $purge->filter_label,
                'deleted_files' => $deletedFiles,
                'freed_bytes'   => $freedBytes,
            ]);
        } catch (Throwable $e) {
            $purge->update([
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 1000),
            ]);
            throw $e;
        }
    }

    /**
     * Borra recursivamente las carpetas vacías dentro de $dir (y $dir si quedó
     * vacío). rmdir solo elimina vacías; las que aún tienen archivos quedan.
     */
    protected function pruneEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
