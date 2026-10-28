<?php

/**
 * Uso (en la raíz de APIDIAN):
 * php sincronizar_listado_erp.php SETP 990000008
 */
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Support\DianListado;
use Illuminate\Support\Facades\DB;

$prefijo = $argv[1] ?? 'SETP';
$numero = isset($argv[2]) ? (int) $argv[2] : 0;

if (!class_exists(DianListado::class)) {
    fwrite(STDERR, "Falta app/Support/DianListado.php. Súbelo antes de ejecutar este script.\n");
    exit(1);
}

$q = DB::table('erp_operaciones_fiscales')->orderBy('creado_en');
$q->where('prefijo', $prefijo);
if ($numero > 0) {
    $q->where('numero', $numero);
}

$n = 0;
foreach ($q->cursor() as $op) {
    DianListado::sincronizarOperacion($op);
    $n++;
    echo "Sincronizada {$op->prefijo}{$op->numero}\n";
}
echo "Total: {$n}\n";
