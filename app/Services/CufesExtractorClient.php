<?php

namespace App\Services;

use GuzzleHttp\Client;

/**
 * Cliente HTTP del "cufes-worker" (extractor Node/Puppeteer): el ÚNICO paso que
 * necesita navegador. El API lo consume por HTTP (localhost o red de Docker)
 * apuntando a EXTRACTOR_URL. Devuelve los CUFEs del listado (método token) SIN
 * descargar ZIPs.
 */
class CufesExtractorClient
{
    /**
     * Obtiene los CUFEs del rango vía el worker (POST {EXTRACTOR_URL}/api/cufes).
     *
     * @return array<int, array<string, mixed>>  items del listado (cufe + metadata)
     *
     * @throws \RuntimeException si EXTRACTOR_URL no está configurada.
     */
    public function getCufes(string $url, string $tipo, string $fechaDesde, string $fechaHasta, ?string $excluirEstados = null): array
    {
        $base = config('services.extractor.url');
        if (empty($base)) {
            throw new \RuntimeException('EXTRACTOR_URL no está configurada en el .env.');
        }

        $client = new Client([
            'base_uri' => rtrim($base, '/') . '/',
            'timeout'  => max((int) config('services.extractor.timeout', 120), 300),
            'verify'   => false,
        ]);

        $headers = ['Accept' => 'application/json'];
        $apiKey = config('services.extractor.api_key');
        if (!empty($apiKey)) {
            $headers['X-Api-Key'] = $apiKey;
        }

        $payload = [
            'url'        => $url,
            'tipo'       => $tipo,
            'fechaDesde' => $fechaDesde,
            'fechaHasta' => $fechaHasta,
        ];
        if (!empty($excluirEstados)) {
            $payload['excluirEstados'] = $excluirEstados;
        }

        $response = $client->post('api/cufes', ['headers' => $headers, 'json' => $payload]);
        $body = json_decode((string) $response->getBody(), true) ?: [];

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * Inicia la obtención de CUFEs en modo ASÍNCRONO (POST /api/cufes con
     * async:true) y devuelve el jobId del worker. El resultado se consulta luego
     * con getCufesJob(). Respuesta inmediata (no espera la extracción).
     *
     * @throws \RuntimeException si EXTRACTOR_URL falta o el worker no da jobId.
     */
    public function submitCufesJob(string $url, string $tipo, string $fechaDesde, string $fechaHasta, ?string $excluirEstados = null): string
    {
        $client = $this->client(20);

        $payload = [
            'url'        => $url,
            'tipo'       => $tipo,
            'fechaDesde' => $fechaDesde,
            'fechaHasta' => $fechaHasta,
            'async'      => true,
        ];
        if (!empty($excluirEstados)) {
            $payload['excluirEstados'] = $excluirEstados;
        }

        $response = $client->post('api/cufes', ['headers' => $this->headers(), 'json' => $payload]);
        $body = json_decode((string) $response->getBody(), true) ?: [];

        if (empty($body['jobId'])) {
            throw new \RuntimeException($body['message'] ?? 'El extractor no devolvió un jobId.');
        }

        return (string) $body['jobId'];
    }

    /**
     * Consulta el estado/resultado de un job de CUFEs del worker
     * (GET /api/jobs/:id). Devuelve el body decodificado: status, data, etc.
     *
     * @return array<string, mixed>
     */
    public function getCufesJob(string $jobId): array
    {
        $client = $this->client(20);
        $response = $client->get('api/jobs/' . rawurlencode($jobId), ['headers' => $this->headers()]);

        return json_decode((string) $response->getBody(), true) ?: [];
    }

    /** Crea un cliente Guzzle apuntando al worker con el timeout indicado. */
    private function client(int $timeout): Client
    {
        $base = config('services.extractor.url');
        if (empty($base)) {
            throw new \RuntimeException('EXTRACTOR_URL no está configurada en el .env.');
        }

        return new Client([
            'base_uri' => rtrim($base, '/') . '/',
            'timeout'  => $timeout,
            'verify'   => false,
        ]);
    }

    /** Headers comunes (Accept + X-Api-Key si está configurada). */
    private function headers(): array
    {
        $headers = ['Accept' => 'application/json'];
        $apiKey = config('services.extractor.api_key');
        if (!empty($apiKey)) {
            $headers['X-Api-Key'] = $apiKey;
        }

        return $headers;
    }
}
