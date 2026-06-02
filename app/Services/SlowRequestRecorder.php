<?php

namespace App\Services;

/**
 * Acumula estadísticas de consultas de la petición HTTP en curso para detectar
 * peticiones lentas. Se registra como singleton: una instancia por petición
 * (php-fpm reinicia el proceso en cada request), alimentada por un listener de
 * consultas y volcada al final de la petición si superó el umbral.
 */
class SlowRequestRecorder
{
    /** @var float|null Marca de inicio (microtime). */
    protected $startedAt = null;

    protected int $queryCount = 0;
    protected float $queryTimeMs = 0.0;

    protected ?string $slowestQuery = null;
    protected float $slowestQueryMs = 0.0;

    /**
     * Marca el inicio de la petición.
     */
    public function start(): void
    {
        $this->startedAt = microtime(true);
    }

    public function started(): bool
    {
        return $this->startedAt !== null;
    }

    /**
     * Registra una consulta ejecutada (desde DB::listen).
     *
     * @param  string  $sql
     * @param  float   $timeMs  Tiempo en milisegundos.
     */
    public function recordQuery(string $sql, float $timeMs): void
    {
        $this->queryCount++;
        $this->queryTimeMs += $timeMs;

        if ($timeMs > $this->slowestQueryMs) {
            $this->slowestQueryMs = $timeMs;
            $this->slowestQuery   = mb_substr($sql, 0, 1000);
        }
    }

    /**
     * Milisegundos transcurridos desde el inicio de la petición.
     */
    public function elapsedMs(): float
    {
        if ($this->startedAt === null) {
            return 0.0;
        }
        return (microtime(true) - $this->startedAt) * 1000;
    }

    /**
     * Resumen de consultas para persistir.
     *
     * @return array{query_count:int, query_time_ms:int, slowest_query:?string, slowest_query_ms:int}
     */
    public function summary(): array
    {
        return [
            'query_count'      => $this->queryCount,
            'query_time_ms'    => (int) round($this->queryTimeMs),
            'slowest_query'    => $this->slowestQuery,
            'slowest_query_ms' => (int) round($this->slowestQueryMs),
        ];
    }
}
