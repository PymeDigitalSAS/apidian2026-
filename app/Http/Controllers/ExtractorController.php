<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ExtractorController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $config = [
            'url'      => config('services.extractor.url'),
            'has_key'  => !empty(config('services.extractor.api_key')),
            'timeout'  => config('services.extractor.timeout'),
        ];

        return view('extractor', compact('config'));
    }

    public function testConnection()
    {
        $url = config('services.extractor.url');
        $apiKey = config('services.extractor.api_key');

        if (empty($url)) {
            return response()->json([
                'success' => false,
                'message' => 'EXTRACTOR_URL no está configurada en el .env',
            ], 422);
        }

        try {
            $client = new Client([
                'base_uri' => rtrim($url, '/') . '/',
                'timeout'  => 10,
                'verify'   => false,
            ]);

            $headers = ['Accept' => 'application/json'];
            if (!empty($apiKey)) {
                $headers['X-Api-Key'] = $apiKey;
            }

            $response = $client->get('api/jobs', ['headers' => $headers]);
            $status = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true);

            return response()->json([
                'success' => true,
                'message' => 'Conexión exitosa con el extractor',
                'status'  => $status,
                'url'     => $url,
                'jobs_count' => is_array($body['jobs'] ?? null) ? count($body['jobs']) : 0,
            ]);
        } catch (ConnectException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar al extractor: ' . $e->getMessage(),
            ], 502);
        } catch (RequestException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';

            if ($status === 401) {
                return response()->json([
                    'success' => false,
                    'message' => 'API key inválida o no configurada en el extractor',
                    'status'  => $status,
                ], 401);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error del extractor (HTTP ' . $status . ')',
                'status'  => $status,
                'body'    => $body,
            ], 502);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error inesperado: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function extract(Request $request)
    {
        $request->validate([
            'url'         => 'required|url',
            'tipo'        => 'required|in:Sent,Received',
            'fechaDesde'  => 'required|date_format:Y-m-d',
            'fechaHasta'  => 'required|date_format:Y-m-d',
        ]);

        $extractorUrl = config('services.extractor.url');
        $apiKey = config('services.extractor.api_key');
        $timeout = config('services.extractor.timeout', 120);

        if (empty($extractorUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'EXTRACTOR_URL no está configurada en el .env',
            ], 422);
        }

        try {
            $client = new Client([
                'base_uri' => rtrim($extractorUrl, '/') . '/',
                'timeout'  => $timeout,
                'verify'   => false,
            ]);

            $headers = ['Accept' => 'application/json'];
            if (!empty($apiKey)) {
                $headers['X-Api-Key'] = $apiKey;
            }

            $payload = [
                'metodo'     => 'url',
                'url'        => $request->input('url'),
                'tipo'       => $request->input('tipo'),
                'fechaDesde' => $request->input('fechaDesde'),
                'fechaHasta' => $request->input('fechaHasta'),
                'async'      => true,
            ];

            $response = $client->post('api/system/consult', [
                'headers' => $headers,
                'json'    => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);

            return response()->json([
                'success' => true,
                'jobId'   => $body['jobId'] ?? null,
                'message' => $body['message'] ?? 'Extracción iniciada',
            ]);
        } catch (ConnectException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar al extractor: ' . $e->getMessage(),
            ], 502);
        } catch (RequestException $e) {
            return $this->handleExtractorException($e, 'extract');
        } catch (\Throwable $e) {
            Log::error('ExtractorController@extract: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error inesperado: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function extractWithCertificate(Request $request)
    {
        $request->validate([
            'url'         => 'required|url',
            'tipo'        => 'required|in:Sent,Received',
            'fechaDesde'  => 'required|date_format:Y-m-d',
            'fechaHasta'  => 'required|date_format:Y-m-d',
            'certificate' => [
                'nullable',
                'file',
                function ($attribute, $value, $fail) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (!in_array($ext, ['p12', 'pfx'])) {
                        $fail('El certificado debe tener extensión .p12 o .pfx');
                    }
                },
            ],
            'p12Password' => 'nullable|string',
            'userCode'    => 'nullable|string|max:64',
        ]);

        // Si se envió contraseña pero no archivo (o viceversa), el backend
        // tratará la corrida como "sin certificado" (Chrome persistente).
        // Esa decisión se delega al extractor (server.js: hasCert).

        $extractorUrl = config('services.extractor.url');
        $apiKey = config('services.extractor.api_key');
        $timeout = config('services.extractor.timeout', 120);

        if (empty($extractorUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'EXTRACTOR_URL no está configurada en el .env',
            ], 422);
        }

        try {
            $client = new Client([
                'base_uri' => rtrim($extractorUrl, '/') . '/',
                'timeout'  => $timeout,
                'verify'   => false,
            ]);

            $headers = ['Accept' => 'application/json'];
            if (!empty($apiKey)) {
                $headers['X-Api-Key'] = $apiKey;
            }

            $payload = [
                'url'        => $request->input('url'),
                'tipo'       => $request->input('tipo'),
                'fechaDesde' => $request->input('fechaDesde'),
                'fechaHasta' => $request->input('fechaHasta'),
                'async'      => true,
            ];

            // El certificado y la contraseña son opcionales: si se envían se
            // hace mTLS contra DIAN; si no, el extractor usa Chrome persistente
            // y reutiliza la sesión iniciada manualmente (ver server.js).
            if ($request->hasFile('certificate')) {
                $payload['p12Base64'] = base64_encode($request->file('certificate')->get());
            }
            if ($request->filled('p12Password')) {
                $payload['p12Password'] = $request->input('p12Password');
            }
            if ($request->filled('userCode')) {
                $payload['userCode'] = $request->input('userCode');
            }

            $response = $client->post('api/certificate', [
                'headers' => $headers,
                'json'    => $payload,
            ]);

            $body = json_decode((string) $response->getBody(), true);

            return response()->json([
                'success' => true,
                'jobId'   => $body['jobId'] ?? null,
                'message' => $body['message'] ?? 'Extracción iniciada',
            ]);
        } catch (ConnectException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar al extractor: ' . $e->getMessage(),
            ], 502);
        } catch (RequestException $e) {
            return $this->handleExtractorException($e, 'extractWithCertificate');
        } catch (\Throwable $e) {
            Log::error('ExtractorController@extractWithCertificate: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error inesperado: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function download(string $path)
    {
        $extractorUrl = config('services.extractor.url');

        if (empty($extractorUrl)) {
            abort(422, 'EXTRACTOR_URL no está configurada en el .env');
        }

        if (strpos($path, '..') !== false) {
            abort(400, 'Ruta inválida');
        }

        try {
            $client = new Client([
                'base_uri' => rtrim($extractorUrl, '/') . '/',
                'timeout'  => 60,
                'verify'   => false,
            ]);

            $segments = array_map('rawurlencode', explode('/', $path));
            $encodedPath = implode('/', $segments);

            $response = $client->get('download/' . $encodedPath, ['stream' => true]);

            $filename = basename($path);
            $contentType = $response->getHeaderLine('Content-Type') ?: 'application/octet-stream';

            return response()->streamDownload(function () use ($response) {
                $body = $response->getBody();
                while (!$body->eof()) {
                    echo $body->read(8192);
                }
            }, $filename, [
                'Content-Type' => $contentType,
            ]);
        } catch (RequestException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 502;
            abort($status === 404 ? 404 : 502, 'No se pudo descargar el archivo del extractor');
        } catch (\Throwable $e) {
            Log::error('ExtractorController@download: ' . $e->getMessage());
            abort(500, 'Error al descargar: ' . $e->getMessage());
        }
    }

    public function jobStatus(string $id)
    {
        $extractorUrl = config('services.extractor.url');
        $apiKey = config('services.extractor.api_key');

        if (empty($extractorUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'EXTRACTOR_URL no está configurada en el .env',
            ], 422);
        }

        try {
            $client = new Client([
                'base_uri' => rtrim($extractorUrl, '/') . '/',
                'timeout'  => 15,
                'verify'   => false,
            ]);

            $headers = ['Accept' => 'application/json'];
            if (!empty($apiKey)) {
                $headers['X-Api-Key'] = $apiKey;
            }

            $response = $client->get('api/jobs/' . rawurlencode($id), ['headers' => $headers]);
            $body = json_decode((string) $response->getBody(), true);

            return response()->json($body);
        } catch (ConnectException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo conectar al extractor: ' . $e->getMessage(),
            ], 502);
        } catch (RequestException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
            return response()->json([
                'success' => false,
                'status'  => $status,
                'body'    => json_decode($body, true) ?: $body,
            ], 502);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error inesperado: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function handleExtractorException(RequestException $e, string $context)
    {
        $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
        $rawBody = $e->getResponse() ? (string) $e->getResponse()->getBody() : '';
        $body = json_decode($rawBody, true) ?: $rawBody;

        $isNoDocsCase = $status === 404
            && is_array($body)
            && isset($body['error'])
            && stripos($body['error'], 'archivo JSON') !== false;

        if ($isNoDocsCase) {
            return response()->json([
                'success' => true,
                'empty'   => true,
                'message' => 'La extracción finalizó pero no se encontraron documentos en el rango de fechas.',
                'data'    => [
                    'log'  => $body['log'] ?? [],
                    'data' => [],
                ],
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Error del extractor (HTTP ' . $status . ')',
            'status'  => $status,
            'body'    => $body,
        ], 502);
    }
}
