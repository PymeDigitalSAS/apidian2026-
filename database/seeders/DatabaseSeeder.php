<?php

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run()
    {
        $this->call(ConfigurationSeeder::class);
        $this->syncPgSequences();
    }

    /**
     * En PostgreSQL las secuencias de id NO avanzan cuando los catalogos
     * se insertan con id explicito (CSVs y migraciones), a diferencia del
     * AUTO_INCREMENT de MySQL. Se resincronizan todas al final para que
     * los INSERT posteriores de la aplicacion no choquen con ids ya usados.
     */
    protected function syncPgSequences()
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $tables = DB::select(
            "SELECT table_name FROM information_schema.columns
             WHERE table_schema = 'public' AND column_name = 'id'"
        );

        foreach ($tables as $table) {
            $seq = DB::selectOne(
                "SELECT pg_get_serial_sequence(?, 'id') AS seq",
                [$table->table_name]
            );
            if ($seq && $seq->seq) {
                DB::statement(
                    "SELECT setval('{$seq->seq}', (SELECT COALESCE(MAX(id), 0) + 1 FROM \"{$table->table_name}\"), false)"
                );
            }
        }

        $this->command->info('Secuencias de PostgreSQL resincronizadas.');
    }
}
