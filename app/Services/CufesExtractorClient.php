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
}
