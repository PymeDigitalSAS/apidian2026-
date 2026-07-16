<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DianXmlQueryService;
use Illuminate\Http\Request;

/**
 * Consulta STATELESS de XMLs por CUFE contra la DIAN (GetXmlByDocumentKey).
 *
 * Recibe el certificado .p12 (base64) + contraseña + lista de CUFEs y devuelve
 * el XML de cada documento en base64. NO requiere empresa registrada.
 *
 * Protegido con API key (header `X-Api-Key` = XML_QUERY_API_KEY del .env).
 * La lógica de firma/consulta vive en DianXmlQueryService.
 */
class XmlByCufeController extends Controller
{
    public function byCufes(Request $request, DianXmlQueryService $xmlQuery)
    {
        // --- Autenticación por API key (stateless) ---
        $expected = (string) config('services.xmlquery.key');
        if ($expected === '' || !hash_equals($expected, (string) $request->header('X-Api-Key'))) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado. Falta o no coincide el header X-Api-Key (XML_QUERY_API_KEY).',
            ], 401);
        }

        $data = $request->validate([
            'certificate' => 'required|string',            // base64 del .p12 / .pfx
            'password'    => 'required|string',
            'ambiente'    => 'required_without:url|in:1,2', // 1=Producción, 2=Habilitación
            'url'         => 'nullable|url',                // opcional: override del endpoint DIAN
            'cufes'       => 'required|array|min:1|max:500',
            'cufes.*'     => 'required|string',
        ]);

        $url = $data['url'] ?? DianXmlQueryService::urlForAmbiente($data['ambiente'] ?? '2');

        $q = $xmlQuery->queryByCufes($data['certificate'], $data['password'], $url, $data['cufes']);
        if (!$q['ok']) {
            return response()->json(['success' => false, 'message' => $q['error']], 422);
        }

        // Convertir el XML crudo a base64 para la respuesta JSON
        $out = array_map(function ($r) {
            if (!empty($r['success'])) {
                return ['cufe' => $r['cufe'], 'success' => true, 'xmlBase64' => base64_encode($r['xml'])];
            }
            return ['cufe' => $r['cufe'], 'success' => false, 'error' => $r['error'] ?? 'Error'];
        }, $q['results']);

        $ok = count(array_filter($out, fn ($r) => $r['success']));

        return response()->json([
            'success' => $ok > 0,
            'total'   => count($out),
            'ok'      => $ok,
            'data'    => $out,
        ]);
    }
}
