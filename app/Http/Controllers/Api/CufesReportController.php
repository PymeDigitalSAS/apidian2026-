<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CufeReportExporter;
use App\Services\CufesExtractorClient;
use App\Services\DianXmlQueryService;
use App\Services\UblDocumentParser;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Request;

/**
 * Orquestador "Por CUFEs" — el API se encarga de TODO:
 *
 *   1) Obtiene los CUFEs del catálogo DIAN llamando al worker Node (navegador).
 *   2) Consulta el XML de cada CUFE con el certificado (GetXmlByDocumentKey).
 *   3) Parsea los XML y arma el reporte (JSON o Excel), formato del extractor.
 *
 * Es stateless (no requiere empresa): el certificado viaja en la petición.
 * Protegido con API key (header X-Api-Key = XML_QUERY_API_KEY).
 *
 * Rutas expuestas con el mismo formato del extractor:
 *   POST /api/cufes        -> solo los CUFEs (proxy al worker)
 *   POST /api/xml-by-cufes -> flujo completo (CUFEs -> XML -> reporte)
 */
class CufesReportController extends Controller
{
    /** @return \Illuminate\Http\JsonResponse|true 401 si la key no valida. */
    private function authorizeApiKey(Request $request)
    {
        $expected = (string) config('services.xmlquery.key');
        if ($expected === '' || !hash_equals($expected, (string) $request->header('X-Api-Key'))) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado. Falta o no coincide el header X-Api-Key (XML_QUERY_API_KEY).',
            ], 401);
        }
        return true;
    }

    /**
     * POST /api/cufes — solo obtiene los CUFEs (proxy al worker). Mismo shape
     * que el /api/cufes del extractor.
     */
    public function cufes(Request $request, CufesExtractorClient $extractor)
    {
        if (($auth = $this->authorizeApiKey($request)) !== true) {
            return $auth;
        }

        $data = $request->validate([
            'url'            => 'required|url',
            'tipo'           => 'required|in:Sent,Received',
            'fechaDesde'     => 'required|date_format:Y-m-d',
            'fechaHasta'     => 'required|date_format:Y-m-d',
            'excluirEstados' => 'nullable|string',
        ]);

        try {
            $items = $extractor->getCufes(
                $data['url'], $data['tipo'], $data['fechaDesde'], $data['fechaHasta'], $data['excluirEstados'] ?? null
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'data' => []], 502);
        }

        return response()->json([
            'success' => count($items) > 0,
            'total'   => count($items),
            'message' => count($items) > 0 ? "Se obtuvieron " . count($items) . " CUFE(s)" : 'Sin CUFEs en el rango indicado',
            'data'    => $items,
        ]);
    }

    /**
     * POST /api/xml-by-cufes — flujo completo: CUFEs (worker) -> XML (cert) ->
     * reporte. Devuelve JSON con los documentos, o el Excel si format=xlsx.
     */
    public function byToken(Request $request, CufesExtractorClient $extractor, DianXmlQueryService $xmlQuery)
    {
        if (($auth = $this->authorizeApiKey($request)) !== true) {
            return $auth;
        }

        $data = $request->validate([
            'url'            => 'required|url',                  // AuthToken DIAN (para los CUFEs)
            'tipo'           => 'required|in:Sent,Received',
            'fechaDesde'     => 'required|date_format:Y-m-d',
            'fechaHasta'     => 'required|date_format:Y-m-d',
            'excluirEstados' => 'nullable|string',
            'certificate'    => 'required|string',               // base64 del .p12 (para los XML)
            'password'       => 'required|string',
            'ambiente'       => 'required_without:dianUrl|in:1,2',
            'dianUrl'        => 'nullable|url',
            'format'         => 'nullable|in:json,xlsx',
        ]);

        // 1) CUFEs (worker Node / navegador)
        try {
            $items = $extractor->getCufes(
                $data['url'], $data['tipo'], $data['fechaDesde'], $data['fechaHasta'], $data['excluirEstados'] ?? null
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Error obteniendo CUFEs: ' . $e->getMessage(), 'data' => []], 502);
        }

        if (empty($items)) {
            return response()->json([
                'success' => false, 'total' => 0,
                'message' => 'No se encontraron CUFEs en el rango indicado.', 'data' => [],
            ]);
        }

        // 2) XML por CUFE (certificado)
        $dianUrl = $data['dianUrl'] ?? DianXmlQueryService::urlForAmbiente($data['ambiente'] ?? '2');
        $cufes = array_values(array_filter(array_map(fn ($i) => $i['cufe'] ?? null, $items)));

        $q = $xmlQuery->queryByCufes($data['certificate'], $data['password'], $dianUrl, $cufes);
        if (!$q['ok']) {
            return response()->json(['success' => false, 'message' => $q['error']], 422);
        }

        $xmlByCufe = [];
        foreach ($q['results'] as $r) {
            $xmlByCufe[$r['cufe']] = $r;
        }

        // 3) Parsear + fusionar metadata del listado con el detalle del XML
        $parser = new UblDocumentParser();
        $rows = [];
        foreach ($items as $item) {
            $cufe = $item['cufe'] ?? '';
            $xr = $xmlByCufe[$cufe] ?? null;

            if ($xr && !empty($xr['success'])) {
                $parsed = $parser->parse($xr['xml']);
                if ($parsed) {
                    $item['dian'] = $parser->mapToDian($parsed);
                    $item['enrich_status'] = 'ok';
                } else {
                    $item['dian'] = null;
                    $item['enrich_status'] = 'error';
                    $item['enrich_message'] = 'El XML no corresponde a Invoice/CreditNote/DebitNote.';
                }
            } else {
                $item['dian'] = null;
                $item['enrich_status'] = 'error';
                $item['enrich_message'] = $xr['error'] ?? 'No se obtuvo el XML de la DIAN.';
            }
            $rows[] = $item;
        }

        // 4) Reporte
        $exporter = new CufeReportExporter();

        if (($data['format'] ?? 'json') === 'xlsx') {
            $path = $exporter->toXlsx($rows);
            return response()->download($path, 'reporte_cufes_' . date('Ymd_His') . '.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $ok = count(array_filter($rows, fn ($r) => ($r['enrich_status'] ?? '') === 'ok'));

        return response()->json([
            'success' => $ok > 0,
            'total'   => count($rows),
            'ok'      => $ok,
            'message' => "Reporte generado: {$ok}/" . count($rows) . " documento(s) con XML.",
            'data'    => $exporter->toArray($rows),
        ]);
    }

    /**
     * POST /api/scrape — flujo completo del extractor por método TOKEN (sin
     * certificado). Es un espejo del /api/scrape del worker: descarga los
     * documentos del listado DIAN y los parsea, devolviendo la misma estructura.
     *
     *   Request:  { url, tipo, fechaDesde, fechaHasta, excluirEstados?, async? }
     *   Response (sync):  { partial, log:[...], data:[ {TipoDocumento, CUFE,
     *                       "Sub Total", TaxBreakdown, LineItems, ...}, ... ] }
     *   Response (async): { success, jobId, statusUrl }  -> consultar GET /api/jobs/{id}
     *
     * OJO: la descarga en la DIAN es lenta (pausas anti-bloqueo por documento),
     * así que para rangos con varios documentos usa async:true + polling del job;
     * en modo síncrono el proxy/servidor puede cortar por timeout.
     * Protegido con X-Api-Key (XML_QUERY_API_KEY).
     */
    public function scrape(Request $request)
    {
        if (($auth = $this->authorizeApiKey($request)) !== true) {
            return $auth;
        }

        $data = $request->validate([
            'url'            => 'required|url',
            'tipo'           => 'required|in:Sent,Received',
            'fechaDesde'     => 'required|date_format:Y-m-d',
            'fechaHasta'     => 'required|date_format:Y-m-d',
            'excluirEstados' => 'nullable|string',
            'async'          => 'nullable|boolean',
        ]);

        $base = config('services.extractor.url');
        if (empty($base)) {
            return response()->json(['success' => false, 'message' => 'EXTRACTOR_URL no está configurada en el .env.'], 422);
        }

        $isAsync = filter_var($request->input('async', false), FILTER_VALIDATE_BOOLEAN);

        try {
            $client = new Client([
                'base_uri' => rtrim($base, '/') . '/',
                // async: solo espera el 202 con jobId. sync: espera toda la extracción.
                'timeout'  => $isAsync ? 20 : max((int) config('services.extractor.timeout', 120), 900),
                'verify'   => false,
            ]);

            $headers = ['Accept' => 'application/json'];
            $apiKey = config('services.extractor.api_key');
            if (!empty($apiKey)) {
                $headers['X-Api-Key'] = $apiKey;
            }

            $payload = [
                'url'        => $data['url'],
                'tipo'       => $data['tipo'],
                'fechaDesde' => $data['fechaDesde'],
                'fechaHasta' => $data['fechaHasta'],
                'async'      => $isAsync,
            ];
            if (!empty($data['excluirEstados'])) {
                $payload['excluirEstados'] = $data['excluirEstados'];
            }

            $resp = $client->post('api/scrape', ['headers' => $headers, 'json' => $payload]);

            // Se devuelve la respuesta del worker tal cual (misma estructura del extractor).
            return response()->json(json_decode((string) $resp->getBody(), true) ?: [], $resp->getStatusCode());
        } catch (ConnectException $e) {
            return response()->json(['success' => false, 'message' => 'No se pudo conectar al extractor: ' . $e->getMessage()], 502);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Error consultando el extractor: ' . $e->getMessage()], 502);
        }
    }

    /**
     * GET /api/jobs/{id} — estado/resultado de un job asíncrono del extractor
     * (espejo del /api/jobs/:id del worker). Cuando status='done' devuelve
     * { status, log, data:[...] } con la misma estructura que el modo síncrono.
     * Protegido con X-Api-Key (XML_QUERY_API_KEY).
     */
    public function job(Request $request, string $id)
    {
        if (($auth = $this->authorizeApiKey($request)) !== true) {
            return $auth;
        }

        $base = config('services.extractor.url');
        if (empty($base)) {
            return response()->json(['success' => false, 'message' => 'EXTRACTOR_URL no está configurada en el .env.'], 422);
        }

        try {
            $client = new Client([
                'base_uri' => rtrim($base, '/') . '/',
                'timeout'  => 20,
                'verify'   => false,
            ]);

            $headers = ['Accept' => 'application/json'];
            $apiKey = config('services.extractor.api_key');
            if (!empty($apiKey)) {
                $headers['X-Api-Key'] = $apiKey;
            }

            $resp = $client->get('api/jobs/' . rawurlencode($id), ['headers' => $headers]);

            return response()->json(json_decode((string) $resp->getBody(), true) ?: [], $resp->getStatusCode());
        } catch (ClientException $e) {
            // 404 = job expirado/inexistente: se reenvía el estado del worker.
            $code = $e->getResponse() ? $e->getResponse()->getStatusCode() : 502;
            $body = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : null;
            return response()->json($body ?: ['success' => false, 'message' => 'Job no encontrado o expirado.'], $code);
        } catch (ConnectException $e) {
            return response()->json(['success' => false, 'message' => 'No se pudo conectar al extractor: ' . $e->getMessage()], 502);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Error consultando el job: ' . $e->getMessage()], 502);
        }
    }
}
