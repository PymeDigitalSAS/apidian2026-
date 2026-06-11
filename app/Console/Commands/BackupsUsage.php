<?php

namespace App\Console\Commands;

use App\Services\BackupUsageService;
use Illuminate\Console\Command;

/**
 * Precalcula y cachea el peso en disco de los archivos por empresa para el panel
 * de Backups. Se ejecuta por cron (ver App\Console\Kernel) para que la vista web
 * siempre lea de caché y nunca recorra el disco bajo una petición del usuario.
 */
class BackupsUsage extends Command
{
    protected $signature = 'backups:usage';

    protected $description = 'Calcula y cachea el peso en disco de los archivos por empresa (panel de Backups).';

    public function handle(BackupUsageService $service): int
    {
        $data = $service->usage(true);

        $this->info(sprintf(
            'Backups: %d empresas con archivos, %s bytes en total (método: %s).',
            $data['companyCount'],
            number_format($data['totalBytes']),
            $data['method']
        ));

        return 0;
    }
}
