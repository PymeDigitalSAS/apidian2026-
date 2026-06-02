<?php

namespace App\Console\Commands;

use App\MonitoringSnapshot;
use App\Services\SystemMonitorService;
use Illuminate\Console\Command;
use Throwable;

class MonitorSnapshot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:snapshot
                            {--prune-days= : Elimina registros más antiguos que N días (por defecto MONITOR_RETENTION_DAYS o 7)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Captura y guarda una instantánea del consumo de recursos del servidor';

    public function handle(SystemMonitorService $monitor): int
    {
        try {
            $data = $monitor->collect();
            $snapshot = MonitoringSnapshot::create($data);

            $this->info(sprintf(
                'Snapshot #%d | CPU %s%% | RAM %s%% | Disco %s%% | Conexiones DB %s',
                $snapshot->id,
                $this->fmt($data['cpu_percent']),
                $this->fmt($data['mem_percent']),
                $this->fmt($data['disk_percent']),
                $data['db_connections'] ?? '-'
            ));
        } catch (Throwable $e) {
            $this->error('No se pudo guardar el snapshot: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->prune();

        return self::SUCCESS;
    }

    protected function prune(): void
    {
        $days = $this->option('prune-days');
        $days = $days !== null ? (int) $days : (int) env('MONITOR_RETENTION_DAYS', 7);

        if ($days <= 0) {
            return;
        }

        try {
            $cutoff  = now()->subDays($days);
            $deleted = MonitoringSnapshot::where('created_at', '<', $cutoff)->delete();
            if ($deleted > 0) {
                $this->line("Registros antiguos eliminados: {$deleted}");
            }
        } catch (Throwable $e) {
            $this->warn('No se pudo limpiar registros antiguos: ' . $e->getMessage());
        }
    }

    protected function fmt($value): string
    {
        return $value === null ? '-' : (string) $value;
    }
}
