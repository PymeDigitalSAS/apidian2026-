<?php

namespace App\Console\Commands;

use App\Support\DianListado;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ErpSincronizarListado extends Command
{
    protected $signature = 'erp:sincronizar-listado {--prefijo=} {--numero=}';

    protected $description = 'Copia CUFE, respuesta DIAN y estado validado del puente ERP al listado de documentos';

    public function handle(): int
    {
        if (!DB::getSchemaBuilder()->hasTable('erp_operaciones_fiscales')) {
            $this->warn('No existe erp_operaciones_fiscales.');

            return self::SUCCESS;
        }

        $consulta = DB::table('erp_operaciones_fiscales')->orderBy('creado_en');
        if ($this->option('prefijo')) {
            $consulta->where('prefijo', $this->option('prefijo'));
        }
        if ($this->option('numero')) {
            $consulta->where('numero', (int) $this->option('numero'));
        }

        $total = 0;
        foreach ($consulta->cursor() as $op) {
            DianListado::sincronizarOperacion($op);
            $total++;
        }
        $this->info("Operaciones sincronizadas: {$total}");

        return self::SUCCESS;
    }
}
