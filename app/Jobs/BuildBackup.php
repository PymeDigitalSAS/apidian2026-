<?php

namespace App\Jobs;

use App\Backup;
use App\Company;
use App\Services\BackupUsageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use ZipArchive;

/**
 * Genera, en segundo plano, el ZIP de un respaldo (una empresa o todas) a partir
 * de los archivos guardados en storage/app/{public,received,xml,zip}. Actualiza
 * el registro App\Backup con el estado, ruta, tamaño y nº de archivos.
 *
 * Corre en la cola 'database' (lo procesa el contenedor worker), por lo que no
 * bloquea ninguna petición web ni hay riesgo de timeout aunque sean GBs.
 */
class BuildBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Una hora para empresas grandes; el worker debe usar --timeout acorde. */
    public $timeout = 3600;

    /** No reintentar: re-comprimir un ZIP a medias no aporta. */
    public $tries = 1;

    public function __construct(public int $backupId)
    {
    }

    public function handle(BackupUsageService $usage): void
    {
        $backup = Backup::find($this->backupId);
        if (!$backup) {
            return;
        }

        $backup->update(['status' => 'processing', 'error' => null]);

        $absPath = null;

        try {
            $dir = storage_path('app/backups');
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            $targets = $backup->scope === 'all'
                ? Company::get(['id', 'identification_number'])
                : Company::where('id', $backup->company_id)->get(['id', 'identification_number']);

            // Filtro por fecha (mtime) del respaldo: null = sin límite.
            $from = $backup->filter_from;
            $to   = $backup->filter_to;

            // 1) Estimar el tamaño y verificar que haya espacio ANTES de empezar.
            //    Los archivos guardados (PDF/ZIP ya comprimidos) hacen que el ZIP
            //    pese ~ lo mismo que la suma en bruto, así que es una guía segura.
            $neededBytes = 0;
            foreach ($targets as $company) {
                foreach ($usage->mapFiles((string) $company->identification_number, (string) $company->id, $from, $to) as $f) {
                    $neededBytes += $f['size'];
                }
            }

            $free = @disk_free_space($dir);
            if ($free !== false && $neededBytes > 0 && $free < $neededBytes) {
                throw new \RuntimeException(sprintf(
                    'Espacio insuficiente en disco: el respaldo ocupa ~%s y solo hay ~%s libres. '
                    . 'Libera espacio (borra respaldos antiguos o archivos) e inténtalo de nuevo.',
                    $this->fmt($neededBytes),
                    $this->fmt($free)
                ));
            }

            // 2) Construir el ZIP.
            $filename = $this->filename($backup);
            $absPath  = $dir . DIRECTORY_SEPARATOR . $filename;

            $zip = new ZipArchive();
            if ($zip->open($absPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('No se pudo crear el archivo ZIP.');
            }

            $count = 0;
            foreach ($targets as $company) {
                $nit = (string) $company->identification_number;
                $cid = (string) $company->id;
                foreach ($usage->mapFiles($nit, $cid, $from, $to) as $f) {
                    $zip->addFile($f['path'], $f['entry']);
                    $count++;
                }
            }

            // Sin archivos: no dejamos un ZIP vacío.
            if ($count === 0) {
                $zip->close();
                if (is_file($absPath)) {
                    @unlink($absPath);
                }
                $backup->update([
                    'status'      => 'done',
                    'disk_path'   => null,
                    'size_bytes'  => 0,
                    'files_count' => 0,
                ]);
                return;
            }

            // 3) Cerrar y VERIFICAR: ZipArchive escribe todo en close(); si el disco
            //    se llenó durante la escritura, close() devuelve false (no lanza).
            if ($zip->close() !== true) {
                throw new \RuntimeException('Error al finalizar el ZIP (posible disco lleno durante la escritura). No se completó el respaldo.');
            }

            $backup->update([
                'status'      => 'done',
                'disk_path'   => 'backups/' . $filename,
                'size_bytes'  => @filesize($absPath) ?: null,
                'files_count' => $count,
            ]);
        } catch (Throwable $e) {
            // Borra el ZIP parcial que pudo quedar a medias (p. ej. disco lleno).
            if ($absPath && is_file($absPath)) {
                @unlink($absPath);
            }
            $backup->update([
                'status' => 'failed',
                'error'  => mb_substr($e->getMessage(), 0, 1000),
            ]);
            throw $e; // que quede también en failed_jobs
        }
    }

    protected function fmt($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0; $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
        return number_format($n, ($n >= 100 || $i === 0) ? 0 : 1) . ' ' . $units[$i];
    }

    /** Nombre del ZIP, único por id de respaldo. */
    protected function filename(Backup $backup): string
    {
        $stamp = $backup->created_at ? $backup->created_at->format('Ymd_His') : date('Ymd_His');

        if ($backup->scope === 'all') {
            return "backup_all_{$stamp}_{$backup->id}.zip";
        }

        $nit = preg_replace('/[^0-9A-Za-z]/', '', (string) $backup->nit);
        return "backup_{$nit}_{$stamp}_{$backup->id}.zip";
    }
}
