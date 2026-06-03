<?php

namespace App\Console\Commands;

use App\MonitoringSnapshot;
use App\Services\SystemMonitorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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

            // Si el CPU está saturado, capturar qué consultas SQL están corriendo
            // en ese instante para saber qué lo está saturando.
            $threshold = (float) config('monitoring.cpu_saturation', 85);
            $saturated = $data['cpu_percent'] !== null && $data['cpu_percent'] >= $threshold;
            if ($saturated) {
                $queries   = $monitor->activeQueries();
                $processes = $monitor->topProcesses();
                $data['top_queries']   = $queries['rows'];
                $data['top_processes'] = $processes['rows'];
                $this->reportCaptureIssue('consultas SQL', $queries);
                $this->reportCaptureIssue('procesos', $processes);
            }

            $snapshot = MonitoringSnapshot::create($data);

            $this->info(sprintf(
                'Snapshot #%d | CPU %s%% | RAM %s%% | Disco %s%%%s',
                $snapshot->id,
                $this->fmt($data['cpu_percent']),
                $this->fmt($data['mem_percent']),
                $this->fmt($data['disk_percent']),
                $saturated
                    ? ' | SATURADO: ' . count($data['top_queries'] ?? []) . ' consultas, '
                        . count($data['top_processes'] ?? []) . ' procesos'
                    : ''
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
        $days = $days !== null ? (int) $days : (int) config('monitoring.retention_days', 7);

        if ($days <= 0) {
            return;
        }

        try {
            $cutoff  = now()->subDays($days);
            $deleted = MonitoringSnapshot::where('created_at', '<', $cutoff)->delete();
            $deleted += \App\MonitoringSlowRequest::where('created_at', '<', $cutoff)->delete();
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

    /**
     * Reporta (consola + log) por qué no se pudo capturar una sección durante una
     * saturación: falta de privilegio PROCESS, sin conexión a BD, contenedor sin
     * pid:host, etc. No interrumpe el guardado del snapshot.
     *
     * @param  array{rows:array, error:?string}  $result
     */
    protected function reportCaptureIssue(string $label, array $result): void
    {
        $error = $result['error'] ?? null;
        if (empty($result['rows']) && $error) {
            $this->warn("No se capturaron {$label}: {$error}");
            Log::warning("[monitor:snapshot] No se capturaron {$label}: {$error}");
        }
    }
}
