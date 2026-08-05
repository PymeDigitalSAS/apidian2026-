<?php
/**
 * Trasvase de datos: MySQL/MariaDB -> PostgreSQL.
 *
 * Copia los datos de una instalacion MySQL existente al esquema PostgreSQL
 * ya creado con `php artisan migrate` (la app debe estar apuntando a la
 * conexion pgsql destino en el .env). NO migra el esquema: solo datos de
 * las tablas comunes. La tabla `migrations` se excluye para conservar el
 * estado de migraciones propio de PostgreSQL.
 *
 * La base MySQL de origen SOLO SE LEE. Las tablas destino se VACIAN antes
 * de copiar.
 *
 * Origen (variables de entorno, con estos defaults):
 *   SRC_DB_HOST=127.0.0.1  SRC_DB_PORT=3306  SRC_DB_DATABASE=apidian
 *   SRC_DB_USERNAME=root   SRC_DB_PASSWORD=
 *
 * Uso:
 *   SRC_DB_DATABASE=apidian2026 php database/tools/transfer_mysql_to_pg.php
 *   (Windows PowerShell): $env:SRC_DB_DATABASE='apidian2026'; php database\tools\transfer_mysql_to_pg.php
 */

chdir(__DIR__ . '/../..');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "ABORTADO: la conexion por defecto (.env) debe ser pgsql; ahora es " . DB::connection()->getDriverName() . PHP_EOL);
    exit(1);
}

config(['database.connections.mysql_source' => [
    'driver' => 'mysql',
    'host' => getenv('SRC_DB_HOST') ?: '127.0.0.1',
    'port' => getenv('SRC_DB_PORT') ?: '3306',
    'database' => getenv('SRC_DB_DATABASE') ?: 'apidian',
    'username' => getenv('SRC_DB_USERNAME') ?: 'root',
    'password' => getenv('SRC_DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'strict' => false,
]]);

$src = DB::connection('mysql_source');
$dst = DB::connection('pgsql');

echo "Origen : mysql " . $src->getDatabaseName() . PHP_EOL;
echo "Destino: pgsql " . $dst->getDatabaseName() . PHP_EOL . PHP_EOL;

// --- tablas comunes -------------------------------------------------------
$srcTables = array_map(fn ($r) => array_values((array) $r)[0], $src->select('SHOW TABLES'));
$dstTables = array_map(fn ($r) => $r->table_name, $dst->select(
    "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
));

$skip = ['migrations'];
$tables = array_values(array_diff(array_intersect($srcTables, $dstTables), $skip));
$onlySrc = array_diff($srcTables, $dstTables);
$onlyDst = array_diff($dstTables, $srcTables, $skip);

if ($onlySrc) echo "AVISO solo en MySQL (no se copian): " . implode(', ', $onlySrc) . PHP_EOL;
if ($onlyDst) echo "AVISO solo en PG (quedan vacias):   " . implode(', ', $onlyDst) . PHP_EOL;
echo PHP_EOL . count($tables) . " tablas a copiar" . PHP_EOL . PHP_EOL;

// --- preparar destino -----------------------------------------------------
// replica: desactiva los triggers de FK para poder cargar en cualquier orden
$dst->statement('SET session_replication_role = replica');

$quoted = array_map(fn ($t) => '"' . $t . '"', $tables);
$dst->statement('TRUNCATE TABLE ' . implode(', ', $quoted) . ' CASCADE');
echo "Tablas destino vaciadas." . PHP_EOL . PHP_EOL;

// --- copia ----------------------------------------------------------------
$transform = function (array $row) {
    foreach ($row as $k => $v) {
        if (is_string($v)) {
            // fechas cero de MySQL: PG las rechaza
            if (strpos($v, '0000-00-00') === 0) {
                $row[$k] = null;
            // PG no admite el byte nulo en text
            } elseif (strpos($v, "\0") !== false) {
                $row[$k] = str_replace("\0", '', $v);
            }
        }
    }
    return $row;
};

$grandSrc = 0; $grandDst = 0; $errors = [];

foreach ($tables as $t) {
    $srcCols = array_map(fn ($r) => $r->Field, $src->select("SHOW COLUMNS FROM `$t`"));
    $dstCols = array_map(fn ($r) => $r->column_name, $dst->select(
        "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ?", [$t]
    ));
    $cols = array_values(array_intersect($srcCols, $dstCols));
    $missing = array_diff($srcCols, $dstCols);

    $total = $src->table($t)->count();
    $grandSrc += $total;
    $label = str_pad($t, 45);

    if ($total === 0) {
        echo "$label 0 filas" . PHP_EOL;
        continue;
    }

    $hasId = in_array('id', $cols, true);
    // arranca por debajo de cualquier id posible (hay catalogos con id = 0)
    $copied = 0; $lastId = PHP_INT_MIN; $offset = 0;

    try {
        while (true) {
            $q = $src->table($t)->select($cols);
            if ($hasId) {
                $rows = $q->where('id', '>', $lastId)->orderBy('id')->limit(1000)->get();
            } else {
                $rows = $q->offset($offset)->limit(1000)->get();
            }
            if ($rows->isEmpty()) break;

            $batch = [];
            foreach ($rows as $r) {
                $batch[] = $transform((array) $r);
            }
            $dst->table($t)->insert($batch);

            $copied += count($batch);
            if ($hasId) { $lastId = end($batch)['id']; } else { $offset += 1000; }
        }
        $grandDst += $copied;
        $note = $missing ? '  [columnas solo en MySQL ignoradas: ' . implode(',', $missing) . ']' : '';
        echo "$label $copied/$total filas$note" . PHP_EOL;
    } catch (\Throwable $e) {
        $errors[$t] = mb_substr($e->getMessage(), 0, 300);
        echo "$label ERROR: {$errors[$t]}" . PHP_EOL;
    }
}

$dst->statement('SET session_replication_role = DEFAULT');

// --- resincronizar secuencias --------------------------------------------
// En PG las secuencias no avanzan al insertar ids explicitos; sin esto, el
// primer INSERT de la app chocaria con ids ya usados.
$seqTables = $dst->select("SELECT table_name FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'id'");
$synced = 0;
foreach ($seqTables as $st) {
    $seq = $dst->selectOne("SELECT pg_get_serial_sequence(?, 'id') AS seq", [$st->table_name]);
    if ($seq && $seq->seq) {
        $dst->statement("SELECT setval('{$seq->seq}', (SELECT COALESCE(MAX(id), 0) + 1 FROM \"{$st->table_name}\"), false)");
        $synced++;
    }
}

echo PHP_EOL . "Secuencias resincronizadas: $synced" . PHP_EOL;
echo "TOTAL: $grandDst de $grandSrc filas copiadas" . PHP_EOL;
echo $errors ? count($errors) . " TABLAS CON ERROR" : "SIN ERRORES";
echo PHP_EOL;
exit($errors ? 1 : 0);
