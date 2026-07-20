<?php

namespace App\Http\Controllers\Api;

use App\ReceivedDocument;
use Illuminate\Http\Request;
use App\Traits\DocumentTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\XmlDocumentRequest;
use App\Services\CufeReportExporter;
use App\Services\CufesExtractorClient;
use App\Services\UblDocumentParser;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RadianEventController extends Controller
{
    use DocumentTrait;

    private function getResponse($success, $message)
    {
        return [
            'success' => $success,
            'message' => $message,
        ];
    }

    protected function processSellerDocumentReception(Request $request)
    {

        try
        {
            $att = new \DOMDocument('1.0', 'utf-8');
            $att->preserveWhiteSpace = false;
            $att->formatOutput = true;

            $company_idnumber = $request->company_idnumber;
            $attXMLStr = str_replace("&", "&amp;", $request->xml_document);

            if(!$att->loadXML(base64_decode($attXMLStr))){
                return $this->getResponse(false, "El archivo no se pudo cargar, revise los problemas asociados");
            }

            else{

                if(!strpos($att->saveXML(), "<AttachedDocument")){
                    return $this->getResponse(false, "El archivo  no es un AttachedDocument XML");
                }

                if(!strpos($att->saveXML(), "<ApplicationResponse")){
                    return $this->getResponse(false, "El archivo no se encontro el ApplicationResponse dentro del AttachedDocument XML");
                }

                if(!strpos($att->saveXML(), "<Invoice")){
                    return $this->getResponse(false, "el archivo no corresponde al AttachedDocument XML de un documento Invoice");
                }

                $invoiceXMLStr = $att->documentElement->getElementsByTagName('Description')->item(0)->nodeValue;
                $invoiceXMLStr = str_replace("&", "&amp;", substr(base64_decode($attXMLStr), strpos(base64_decode($attXMLStr), "<Invoice"), strpos(base64_decode($attXMLStr), "/Invoice>") - strpos(base64_decode($attXMLStr), "<Invoice") + 9));
                $invoiceXMLStr = preg_replace("/[\r\n|\n|\r]+/", "","<?xml version=\"1.0\" encoding=\"utf-8\"?>".$invoiceXMLStr);

                $invoice_doc = new \stdClass;
                $invoice_doc->identification_number = $this->ValueXML($invoiceXMLStr, "/Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID/");
                $invoice_doc->dv = $this->validarDigVerifDIAN($invoice_doc->identification_number);
                $invoice_doc->name_seller = $this->getTag($invoiceXMLStr, 'RegistrationName', 0)->nodeValue;
                $invoice_doc->state_document_id = 1;
                $invoice_doc->type_document_id = $this->getTag($invoiceXMLStr, 'InvoiceTypeCode', 0)->nodeValue;
                $invoice_doc->customer = $this->ValueXML($invoiceXMLStr, "/Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID/");
                if(strpos($invoiceXMLStr, "</sts:Prefix>"))
                    $invoice_doc->prefix = $this->getTag($invoiceXMLStr, 'Prefix', 0)->nodeValue;
                else
                    $invoice_doc->prefix = "";
                $i = 0;
                if($invoice_doc->prefix != "")
                    do{
//                            $invoice_doc->number =  $this->ValueXML($invoiceXMLStr, "/Invoice/cbc:ID/");
                        $invoice_doc->number =  $this->getTag($invoiceXMLStr, "ID", $i)->nodeValue;
                        $i++;
                    }while(strpos($invoice_doc->number, $invoice_doc->prefix) === false);
                else
                    $invoice_doc->number =  $this->ValueXML($invoiceXMLStr, "/Invoice/cbc:ID/");

                $invoice_doc->xml = null;
                $invoice_doc->cufe = $this->getTag($invoiceXMLStr, 'UUID', 0)->nodeValue;
                $invoice_doc->date_issue = $this->getTag($invoiceXMLStr, 'IssueDate', 0)->nodeValue.' '.str_replace('-05:00', '', $this->getTag($invoiceXMLStr, 'IssueTime', 0)->nodeValue);
                $invoice_doc->sale = $this->getTag($invoiceXMLStr, 'TaxInclusiveAmount', 0)->nodeValue;
                if(isset($this->getTag($invoiceXMLStr, 'AllowanceTotalAmount', 0)->nodeValue))
                    $invoice_doc->total_discount =  $this->getTag($invoiceXMLStr, 'AllowanceTotalAmount', 0)->nodeValue;
                else
                    $invoice_doc->total_discount = 0;
                $invoice_doc->subtotal = $this->getTag($invoiceXMLStr, 'LineExtensionAmount', 0)->nodeValue;
                $invoice_doc->total_tax = $invoice_doc->sale - $invoice_doc->subtotal;
                $invoice_doc->total = $this->getTag($invoiceXMLStr, 'PayableAmount', 0)->nodeValue;
                $invoice_doc->ambient_id = $this->getTag($invoiceXMLStr, 'ProfileExecutionID', 0)->nodeValue;
                $invoice_doc->pdf = null;
                $invoice_doc->acu_recibo = 0;
                $invoice_doc->rec_bienes = 0;
                $invoice_doc->aceptacion = 0;
                $invoice_doc->rechazo = 0;

                if($invoice_doc->customer != $company_idnumber){
                    return $this->getResponse(false, "El archivo  no corresponde un AttachedDocument XML del adquiriente ".$company_idnumber);

                }

                $exists = ReceivedDocument::where('customer', $company_idnumber)->where('identification_number', $invoice_doc->identification_number)->where('prefix', $invoice_doc->prefix)->where('number', $invoice_doc->number)->get();

                if(count($exists) == 0)
                {
                    return [
                        'success' => true,
                        'message' => "El archivo fue cargado satisfactoriamente...",
                        'data' => $invoice_doc,
                    ];
                }
                else{
                    return $this->getResponse(false, "El archivo  ya existe en la base de datos...");
                }

                return $this->getResponse(true, "El archivo fue cargado satisfactoriamente...");

            }

        }
        catch (\Exception $e)
        {
            return $this->getResponse(false, "Error en la carga: {$e->getMessage()}");
        }
    }

    /**
     * Cargar datos de un documento desde la DIAN usando el CUFE, sin enviar evento.
     * Opcionalmente guarda el registro en received_documents.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function loadDocumentByCufe(Request $request)
    {
        try {
            // User company
            $user = auth()->user();
            $company = $user->company;

            // Validar CUFE requerido
            if (!$request->cufe) {
                return $this->getResponse(false, 'El campo cufe es requerido.');
            }

            // Verificar que la empresa esté activa
            if ($company->state == false) {
                return $this->getResponse(false, 'La empresa se encuentra en el momento INACTIVA para enviar documentos electronicos...');
            }

            // Verificar que el software esté configurado
            if (!$company->software) {
                return $this->getResponse(false, 'La empresa no tiene configurado el software de facturación.');
            }

            // Consultar y parsear el documento en la DIAN por CUFE
            $built = $this->buildDocumentByCufe($company, $request->cufe);

            if (!$built['success']) {
                return [
                    'success' => false,
                    'message' => $built['message'],
                ];
            }

            $invoice_doc = $built['data'];

            // Verificar si el adquiriente corresponde a la empresa
            if (!$built['customer_match']) {
                return [
                    'success' => false,
                    'message' => "El adquiriente del documento no corresponde a la empresa con nit: " . $company->identification_number,
                    'data' => $invoice_doc,
                ];
            }

            // Verificar si ya existe en received_documents
            $exists = ReceivedDocument::where('customer', $company->identification_number)
                ->where('identification_number', $invoice_doc->identification_number)
                ->where('prefix', $invoice_doc->prefix)
                ->where('number', $invoice_doc->number)
                ->get();

            if (count($exists) > 0) {
                return [
                    'success' => true,
                    'message' => 'El documento ya existe en la base de datos.',
                    'data' => $exists[0],
                    'already_exists' => true,
                ];
            }

            // Guardar si se solicita
            if ($request->save && $request->save == true) {
                $receivedDoc = $this->persistReceivedDocument($invoice_doc);

                return [
                    'success' => true,
                    'message' => 'El documento fue cargado y guardado satisfactoriamente.',
                    'data' => $receivedDoc,
                    'saved' => true,
                ];
            }

            return [
                'success' => true,
                'message' => 'Datos del documento obtenidos satisfactoriamente.',
                'data' => $invoice_doc,
                'saved' => false,
            ];

        } catch (\Exception $e) {
            return $this->getResponse(false, "Error al cargar documento por CUFE: {$e->getMessage()}");
        }
    }

    /**
     * Enriquecer un lote de CUFEs con los datos del XML de la DIAN.
     *
     * Flujo "Reporte por CUFEs": el navegador obtiene la lista de CUFEs desde el
     * extractor y luego envía lotes de items (cada uno con su metadata del
     * extractor) a este endpoint. Cada item vuelve con un sub-objeto `dian`
     * (detalle del XML) y un `enrich_status` (ok | mismatch | error).
     *
     * No persiste nada: es solo lectura/consulta para armar el reporte.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function enrichCufes(Request $request)
    {
        try {
            $user = auth()->user();
            $company = $user->company;

            if ($company->state == false) {
                return $this->getResponse(false, 'La empresa se encuentra en el momento INACTIVA para consultar documentos electronicos...');
            }

            if (!$company->software) {
                return $this->getResponse(false, 'La empresa no tiene configurado el software de facturación.');
            }

            $items = $request->input('items', []);
            if (!is_array($items) || count($items) === 0) {
                return $this->getResponse(false, 'Debe enviar un arreglo "items" con al menos un CUFE.');
            }

            // 'Received' (por defecto) valida que la empresa sea el adquiriente
            // (para poder registrar eventos RADIAN). 'Sent' = documentos que la
            // empresa emitió: son solo para el reporte, así que no se exige el match.
            $tipo = $request->input('tipo') === 'Sent' ? 'Sent' : 'Received';

            // Si el cliente pide el XML (p. ej. "Consulta por CUFE"), se incluye
            // en base64 por fila; así una sola consulta a la DIAN devuelve tanto
            // los datos estructurados como el XML del documento.
            $includeXml = filter_var($request->input('include_xml', false), FILTER_VALIDATE_BOOLEAN);

            // Tope defensivo por lote: el enriquecimiento hace 1 consulta SOAP a
            // la DIAN por CUFE, por eso el front lo llama por lotes pequeños.
            if (count($items) > 25) {
                return $this->getResponse(false, 'Máximo 25 CUFEs por lote. Envíe los CUFEs en lotes más pequeños.');
            }

            $rows = [];
            foreach ($items as $item) {
                // Aceptar tanto strings ("cufe") como objetos {cufe, ...metadata}
                $item = is_array($item) ? $item : ['cufe' => $item];
                $cufe = $item['cufe'] ?? null;
                $row  = $item; // conservar la metadata del extractor

                if (!$cufe) {
                    $row['dian'] = null;
                    $row['enrich_status'] = 'error';
                    $row['enrich_message'] = 'El item no contiene CUFE.';
                    $rows[] = $row;
                    continue;
                }

                try {
                    $built = $this->buildDocumentByCufe($company, $cufe);

                    if (!$built['success']) {
                        $row['dian'] = null;
                        $row['root'] = null;
                        $row['enrich_status'] = 'error';
                        $row['enrich_message'] = $built['message'];
                    } elseif ($tipo === 'Sent') {
                        // Enviados: solo reporte, no se valida el adquiriente.
                        $row['dian'] = $built['data'];
                        $row['root'] = $built['root'];
                        $row['enrich_status'] = 'ok';
                        $row['enrich_message'] = 'OK';
                    } else {
                        $row['dian'] = $built['data'];
                        $row['root'] = $built['root'];
                        $row['enrich_status'] = $built['customer_match'] ? 'ok' : 'mismatch';
                        $row['enrich_message'] = $built['customer_match']
                            ? 'OK'
                            : 'El adquiriente del documento no corresponde al NIT ' . $company->identification_number . '.';
                    }

                    if ($includeXml && !empty($built['xml'])) {
                        $row['xml_b64'] = base64_encode($built['xml']);
                    }
                } catch (\Throwable $e) {
                    $row['dian'] = null;
                    $row['root'] = null;
                    $row['enrich_status'] = 'error';
                    $row['enrich_message'] = $e->getMessage();
                }

                $rows[] = $row;
            }

            return [
                'success' => true,
                'total'   => count($rows),
                'rows'    => $rows,
            ];

        } catch (\Exception $e) {
            return $this->getResponse(false, "Error al enriquecer CUFEs: {$e->getMessage()}");
        }
    }

    // ============================================================
    // Extracción por token AUTENTICADA con el token de la empresa.
    // El cliente NO envía certificado: se usa el que la empresa ya tiene
    // configurado. Flujo asíncrono (jobId + polling): el enriquecimiento con el
    // certificado se hace por lotes durante cada consulta de estado, así ninguna
    // petición queda abierta mucho tiempo (sin depender de un worker de cola).
    // ============================================================

    /** Prefijo y TTL de los jobs de extracción guardados en caché. */
    private const EXTRACT_JOB_PREFIX = 'extractor_job:';
    private const EXTRACT_JOB_TTL = 7200;        // 2 horas
    private const EXTRACT_BATCH = 5;             // CUFEs enriquecidos por consulta

    /**
     * POST /api/extractor/company — inicia la extracción usando el certificado
     * de la empresa autenticada (token Bearer). Devuelve un jobId al instante.
     *
     * Body: { url, tipo, fechaDesde, fechaHasta, excluirEstados? }
     * Respuesta: { success, jobId, statusUrl }
     */
    public function startCompanyExtraction(Request $request, CufesExtractorClient $extractor)
    {
        try {
            $company = auth()->user()->company;

            if ($company->state == false) {
                return response()->json($this->getResponse(false, 'La empresa se encuentra INACTIVA para consultar documentos electrónicos.'), 422);
            }
            if (!$company->software) {
                return response()->json($this->getResponse(false, 'La empresa no tiene configurado el software de facturación.'), 422);
            }
            if (!$company->certificate) {
                return response()->json($this->getResponse(false, 'La empresa no tiene un certificado (.p12) configurado.'), 422);
            }

            $data = $request->validate([
                'url'            => 'required|url',
                'tipo'           => 'required|in:Sent,Received',
                'fechaDesde'     => 'required|date_format:Y-m-d',
                'fechaHasta'     => 'required|date_format:Y-m-d',
                'excluirEstados' => 'nullable|string',
            ]);

            // 1) Arranca el job de CUFEs en el worker (async, respuesta inmediata).
            $workerJobId = $extractor->submitCufesJob(
                $data['url'], $data['tipo'], $data['fechaDesde'], $data['fechaHasta'], $data['excluirEstados'] ?? null
            );

            // 2) Guarda el estado del job de extracción en caché.
            $jobId = (string) Str::uuid();
            $this->saveExtractJob($jobId, [
                'company_id'        => $company->id,
                'company_nit'       => $company->identification_number,
                'tipo'              => $data['tipo'],     // Received | Sent (Sent = solo reporte)
                'status'            => 'getting_cufes',   // getting_cufes | enriching | done | error
                'worker_job_id'     => $workerJobId,
                'worker_log_offset' => 0,
                'cufes'             => null,
                'cursor'            => 0,
                'total'             => 0,
                'rows'              => [],
                'log'               => ['✅ Extracción iniciada', '🟡 Obteniendo CUFEs del catálogo DIAN…'],
                'error'             => null,
            ]);

            return response()->json([
                'success'   => true,
                'jobId'     => $jobId,
                'statusUrl' => url('/api/extractor/company/jobs/' . $jobId),
                'message'   => 'Extracción iniciada. Consulte statusUrl para el avance y el resultado.',
            ], 202);

        } catch (\Throwable $e) {
            return response()->json($this->getResponse(false, 'No se pudo iniciar la extracción: ' . $e->getMessage()), 502);
        }
    }

    /**
     * GET /api/extractor/company/jobs/{id} — avance y resultado del job.
     * Hace avanzar la máquina de estados en cada consulta:
     *   getting_cufes -> (worker listo) -> enriching -> (por lotes) -> done.
     *
     * Respuesta: { success, status, stage, log, total, processed, data }
     *   status: running | done | error   |   data: solo cuando status='done'.
     */
    public function companyExtractionStatus(Request $request, string $id, CufesExtractorClient $extractor)
    {
        $company = auth()->user()->company;
        $job = $this->loadExtractJob($id);

        // 404 si no existe/expiró o no pertenece a la empresa autenticada.
        if (!$job || (int) ($job['company_id'] ?? 0) !== (int) $company->id) {
            return response()->json($this->getResponse(false, 'Job no encontrado o expirado. Vuelva a iniciar la extracción.'), 404);
        }

        try {
            if ($job['status'] === 'getting_cufes') {
                $job = $this->advanceGettingCufes($job, $extractor);
            } elseif ($job['status'] === 'enriching') {
                $job = $this->advanceEnriching($job, $company);
            }
        } catch (\Throwable $e) {
            $job['status'] = 'error';
            $job['error']  = $e->getMessage();
            $job['log'][]  = '❌ ' . $e->getMessage();
        }

        $this->saveExtractJob($id, $job);

        $status = in_array($job['status'], ['done', 'error'], true) ? $job['status'] : 'running';
        $stage  = $job['status'];   // getting_cufes | enriching | done | error

        $payload = [
            'success'   => $job['status'] !== 'error',
            'status'    => $status,
            'stage'     => $stage,
            'total'     => (int) $job['total'],
            'processed' => (int) $job['cursor'],
            'log'       => $job['log'],
        ];

        if ($job['status'] === 'done') {
            $exporter = new CufeReportExporter();
            $payload['data'] = $exporter->toArray($job['rows']);
        } elseif ($job['status'] === 'error') {
            $payload['message'] = $job['error'];
        }

        return response()->json($payload);
    }

    /**
     * POST /api/extractor/company/stream — extracción con token de empresa en UN
     * SOLO endpoint. Hace todo el proceso (obtiene los CUFEs con el navegador y
     * consulta cada XML con el certificado de la empresa) y devuelve el JSON
     * { log, data } cuando TERMINA. No es en vivo: el cliente recibe la respuesta
     * completa al final, como el extractor original.
     *
     * Respuesta: { "log":[ ...líneas del proceso... ], "data":[ ...documentos... ] }
     */
    public function streamCompanyExtraction(Request $request, CufesExtractorClient $extractor)
    {
        $company = auth()->user()->company;

        if ($company->state == false) {
            return response()->json($this->getResponse(false, 'La empresa se encuentra INACTIVA para consultar documentos electrónicos.'), 422);
        }
        if (!$company->software) {
            return response()->json($this->getResponse(false, 'La empresa no tiene configurado el software de facturación.'), 422);
        }
        if (!$company->certificate) {
            return response()->json($this->getResponse(false, 'La empresa no tiene un certificado (.p12) configurado.'), 422);
        }

        $data = $request->validate([
            'url'            => 'required|url',
            'tipo'           => 'required|in:Sent,Received',
            'fechaDesde'     => 'required|date_format:Y-m-d',
            'fechaHasta'     => 'required|date_format:Y-m-d',
            'excluirEstados' => 'nullable|string',
        ]);

        @set_time_limit(0);

        $log = [];
        $add = function (string $line) use (&$log) {
            $log[] = str_replace(["\r", "\n"], ' ', $line);
        };

        try {
            $add('✅ Extracción iniciada');
            $add('🟡 Obteniendo CUFEs del catálogo DIAN…');

            // 1) CUFEs vía el worker (job async + espera hasta que termine).
            $workerJobId = $extractor->submitCufesJob(
                $data['url'], $data['tipo'], $data['fechaDesde'], $data['fechaHasta'], $data['excluirEstados'] ?? null
            );

            $seen = 0;
            $cufes = null;
            for ($i = 0; $i < 300; $i++) {   // ~10 min de techo
                $wj   = $extractor->getCufesJob($workerJobId);
                $wlog = is_array($wj['log'] ?? null) ? $wj['log'] : [];
                for ($k = $seen; $k < count($wlog); $k++) {
                    $add($wlog[$k]);
                }
                $seen = count($wlog);

                $st = $wj['status'] ?? 'running';
                if ($st === 'error') {
                    $add('❌ ' . ($wj['error'] ?? 'El extractor falló obteniendo los CUFEs.'));
                    return response()->json(['log' => $log, 'data' => []]);
                }
                if ($st === 'done') {
                    $cufes = is_array($wj['data'] ?? null) ? $wj['data'] : [];
                    break;
                }
                sleep(2);
            }

            if ($cufes === null) {
                $add('❌ Tiempo de espera agotado obteniendo los CUFEs.');
                return response()->json(['log' => $log, 'data' => []]);
            }

            if (empty($cufes)) {
                $add('ℹ️ Sin CUFEs en el rango indicado.');
                return response()->json(['log' => $log, 'data' => []]);
            }

            $total = count($cufes);
            $add("🟢 CUFEs obtenidos: {$total}. Consultando la DIAN por cada uno…");

            // 2) Enriquecer cada CUFE con el certificado de la empresa.
            $rows = [];
            $n = 0;
            foreach ($cufes as $item) {
                $n++;
                $item = is_array($item) ? $item : ['cufe' => $item];
                $cufe = $item['cufe'] ?? null;
                $row  = $item;

                try {
                    if (!$cufe) {
                        throw new \RuntimeException('El item no contiene CUFE.');
                    }
                    $built = $this->buildDocumentByCufe($company, $cufe);
                    if (!$built['success']) {
                        $row['dian'] = null;
                        $row['root'] = null;
                        $row['enrich_status'] = 'error';
                        $row['enrich_message'] = $built['message'];
                    } elseif ($data['tipo'] === 'Sent') {
                        // Enviados: solo reporte, no se valida el adquiriente.
                        $row['dian'] = $built['data'];
                        $row['root'] = $built['root'];
                        $row['enrich_status'] = 'ok';
                        $row['enrich_message'] = 'OK';
                    } else {
                        $row['dian'] = $built['data'];
                        $row['root'] = $built['root'];
                        $row['enrich_status'] = $built['customer_match'] ? 'ok' : 'mismatch';
                        $row['enrich_message'] = $built['customer_match']
                            ? 'OK'
                            : 'El adquiriente no corresponde al NIT ' . $company->identification_number . '.';
                    }
                } catch (\Throwable $e) {
                    $row['dian'] = null;
                    $row['root'] = null;
                    $row['enrich_status'] = 'error';
                    $row['enrich_message'] = $e->getMessage();
                }

                $rows[] = $row;
                $add("⬇️ Procesado {$n}/{$total} [" . $row['enrich_status'] . ']');
            }

            $add('✅ Proceso terminado');

            $exporter = new CufeReportExporter();
            return response()->json(['log' => $log, 'data' => $exporter->toArray($rows)]);
        } catch (\Throwable $e) {
            $add('❌ ' . $e->getMessage());
            return response()->json(['log' => $log, 'data' => []]);
        }
    }

    /** Consulta el job del worker; al terminar pasa a enriquecer. */
    private function advanceGettingCufes(array $job, CufesExtractorClient $extractor): array
    {
        $wj = $extractor->getCufesJob($job['worker_job_id']);
        $wstatus = $wj['status'] ?? 'running';

        // Copia al log del job las líneas NUEVAS del worker (pasos del navegador),
        // así el polling muestra el avance en vivo, no solo "obteniendo CUFEs…".
        $wlog = is_array($wj['log'] ?? null) ? $wj['log'] : [];
        $off  = (int) ($job['worker_log_offset'] ?? 0);
        for ($k = $off; $k < count($wlog); $k++) {
            $job['log'][] = $wlog[$k];
        }
        $job['worker_log_offset'] = count($wlog);

        if ($wstatus === 'running') {
            return $job; // sigue obteniendo CUFEs
        }

        if ($wstatus === 'error') {
            $job['status'] = 'error';
            $job['error']  = $wj['error'] ?? 'El extractor falló obteniendo los CUFEs.';
            $job['log'][]  = '❌ ' . $job['error'];
            return $job;
        }

        // done (o parcial con datos)
        $cufes = is_array($wj['data'] ?? null) ? $wj['data'] : [];
        if (empty($cufes)) {
            $job['status'] = 'done';
            $job['total']  = 0;
            $job['log'][]  = 'ℹ️ Sin CUFEs en el rango indicado.';
            return $job;
        }

        $job['cufes']  = $cufes;
        $job['total']  = count($cufes);
        $job['status'] = 'enriching';
        $job['log'][]  = '🟢 CUFEs obtenidos: ' . count($cufes) . '. Consultando la DIAN por cada uno…';
        return $job;
    }

    /** Enriquece el siguiente lote de CUFEs con el certificado de la empresa. */
    private function advanceEnriching(array $job, $company): array
    {
        $cufes = $job['cufes'] ?? [];
        $start = (int) $job['cursor'];
        $slice = array_slice($cufes, $start, self::EXTRACT_BATCH);

        foreach ($slice as $item) {
            $item = is_array($item) ? $item : ['cufe' => $item];
            $cufe = $item['cufe'] ?? null;
            $row  = $item;

            if (!$cufe) {
                $row['dian'] = null;
                $row['enrich_status'] = 'error';
                $row['enrich_message'] = 'El item no contiene CUFE.';
                $job['rows'][] = $row;
                continue;
            }

            try {
                $built = $this->buildDocumentByCufe($company, $cufe);
                if (!$built['success']) {
                    $row['dian'] = null;
                    $row['root'] = null;
                    $row['enrich_status'] = 'error';
                    $row['enrich_message'] = $built['message'];
                } elseif (($job['tipo'] ?? 'Received') === 'Sent') {
                    // Enviados: solo reporte, no se valida el adquiriente.
                    $row['dian'] = $built['data'];
                    $row['root'] = $built['root'];
                    $row['enrich_status'] = 'ok';
                    $row['enrich_message'] = 'OK';
                } else {
                    $row['dian'] = $built['data'];
                    $row['root'] = $built['root'];
                    $row['enrich_status'] = $built['customer_match'] ? 'ok' : 'mismatch';
                    $row['enrich_message'] = $built['customer_match']
                        ? 'OK'
                        : 'El adquiriente no corresponde al NIT ' . $company->identification_number . '.';
                }
            } catch (\Throwable $e) {
                $row['dian'] = null;
                $row['root'] = null;
                $row['enrich_status'] = 'error';
                $row['enrich_message'] = $e->getMessage();
            }

            $job['rows'][] = $row;
        }

        $job['cursor'] = $start + count($slice);
        $job['log'][]  = "⬇️ Procesados {$job['cursor']}/{$job['total']} documento(s)…";

        if ($job['cursor'] >= $job['total']) {
            $job['status'] = 'done';
            $job['log'][]  = '✅ Proceso terminado';
        }

        return $job;
    }

    private function saveExtractJob(string $id, array $job): void
    {
        Cache::put(self::EXTRACT_JOB_PREFIX . $id, $job, self::EXTRACT_JOB_TTL);
    }

    private function loadExtractJob(string $id): ?array
    {
        return Cache::get(self::EXTRACT_JOB_PREFIX . $id);
    }

    /**
     * Generar el reporte descargable (XLSX o JSON) a partir de las filas ya
     * enriquecidas que el navegador acumuló desde enrichCufes.
     *
     * Body: { rows: [...], format: "xlsx"|"json", filenameBase?: string }
     *
     * @param \Illuminate\Http\Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse
     */
    public function cufesReport(Request $request)
    {
        $rows = $request->input('rows', []);
        if (!is_array($rows) || count($rows) === 0) {
            return response()->json($this->getResponse(false, 'Debe enviar un arreglo "rows" con al menos una fila.'), 422);
        }

        $format = strtolower((string) $request->input('format', 'xlsx'));

        $base = $request->input('filenameBase');
        $base = $base
            ? preg_replace('/[^A-Za-z0-9_\-]/', '_', $base)
            : 'reporte_cufes_' . date('Ymd_His');

        $exporter = new CufeReportExporter();

        if ($format === 'json') {
            $payload = [
                'generated_at' => date('c'),
                'total' => count($rows),
                'data' => $exporter->toArray($rows),
            ];

            return response()->streamDownload(function () use ($payload) {
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }, $base . '.json', [
                'Content-Type' => 'application/json; charset=UTF-8',
            ]);
        }

        // El Excel requiere PhpSpreadsheet. Si el cliente aún no instaló la
        // dependencia, avisar con un mensaje claro en vez de un error fatal
        // (Class "PhpOffice\PhpSpreadsheet\Spreadsheet" not found). El JSON no la usa.
        if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            return response()->json([
                'success' => false,
                'message' => 'El Excel requiere la librería PhpSpreadsheet, que no está instalada en el servidor. '
                    . 'Ejecute en el servidor: composer update phpoffice/phpspreadsheet --with-dependencies '
                    . 'Mientras tanto, puede descargar el reporte en formato JSON.',
            ], 422);
        }

        // XLSX por defecto
        $path = $exporter->toXlsx($rows);

        return response()->download($path, $base . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Consulta el XML de un documento en la DIAN por CUFE y extrae sus datos.
     * Soporta Invoice, CreditNote y DebitNote. No persiste nada.
     *
     * @param  \App\Company $company
     * @param  string       $cufe
     * @return array{success:bool, message:string, data:?\stdClass, customer_match:bool, root:?string, xml:?string}
     */
    protected function buildDocumentByCufe($company, $cufe): array
    {
        // Consultar XML en la DIAN por CUFE (verificarEstadoDIAN y verify_certificate
        // se ejecutan dentro de XmlDocumentController).
        $xmlDIAN = new XmlDocumentController();
        $send = ['is_event' => true];
        $r = $xmlDIAN->document(new XmlDocumentRequest($send), $cufe);

        if (!isset($r['success']) || !$r['success']) {
            // Razón real del fallo: primero el texto de ResponseDian (Message de
            // la DIAN o Fault SOAP; puede llegar como string u objeto anidado),
            // luego el message del controlador, y por último un genérico.
            $msg = $this->extractDianText($r['ResponseDian'] ?? null)
                ?? (isset($r['message']) && is_string($r['message']) && trim($r['message']) !== '' ? $r['message'] : null)
                ?? 'No se pudo obtener el documento de la DIAN.';

            return [
                'success' => false,
                'message' => $msg,
                'data' => null,
                'customer_match' => false,
                'root' => null,
                'xml' => null,
            ];
        }

        $xml = base64_decode(json_encode($r['ResponseDian']->Envelope->Body->GetXmlByDocumentKeyResponse->GetXmlByDocumentKeyResult->XmlBytesBase64));

        // Parsear el UBL con namespaces registrados (soporta Invoice/CreditNote/
        // DebitNote y desenvuelve AttachedDocument si viniera envuelto).
        $parsed = (new UblDocumentParser())->parse($xml);

        if ($parsed === null) {
            return [
                'success' => false,
                'message' => 'El CUFE ingresado no corresponde al XML de un documento Invoice, CreditNote o DebitNote.',
                'data' => null,
                'customer_match' => false,
                'root' => null,
                'xml' => null,
            ];
        }

        // Mapear a la estructura usada por received_documents + campos ricos
        // (líneas, impuestos, retenciones) para el reporte "Por CUFEs".
        $doc = new \stdClass;
        $doc->identification_number = $parsed['supplier']['nit'];
        $doc->dv = $doc->identification_number ? $this->validarDigVerifDIAN($doc->identification_number) : null;
        $doc->name_seller = $parsed['supplier']['name'] ?? '';
        $doc->state_document_id = 1;
        $doc->type_document_id = $parsed['type_code'];
        $doc->customer = $parsed['customer']['nit'];
        $doc->customer_name = $parsed['customer']['name'] ?? '';
        $doc->prefix = $parsed['prefix'] ?? '';
        $doc->number = $parsed['number'];
        $doc->cufe = $parsed['cufe'] ?? $cufe;
        $doc->date_issue = trim(($parsed['issue_date'] ?? '') . ' ' . ($parsed['issue_time'] ?? ''));
        $doc->sale = $parsed['tax_inclusive'];
        $doc->total_discount = $parsed['allowance_total'] ?? 0;
        $doc->subtotal = $parsed['line_extension'];
        $doc->total_tax = $parsed['header_taxes_total'];
        $doc->total = $parsed['payable'];
        $doc->ambient_id = $parsed['ambient_id'];
        $doc->pdf = null;
        $doc->acu_recibo = 0;
        $doc->rec_bienes = 0;
        $doc->aceptacion = 0;
        $doc->rechazo = 0;

        // Campos ricos adicionales (no se persisten en received_documents; solo reporte).
        $doc->doc_type = $parsed['doc_type'];               // Invoice/CreditNote/DebitNote
        $doc->currency = $parsed['currency'];
        $doc->due_date = $parsed['due_date'];
        $doc->tax_exclusive = $parsed['tax_exclusive'];
        $doc->charge_total = $parsed['charge_total'] ?? 0;
        $doc->retention_total = $parsed['withholdings_total'] ?? 0;
        $doc->payment_means_id = $parsed['payment_means_id'];
        $doc->payment_means_code = $parsed['payment_means_code'];
        $doc->note = $parsed['note'];
        $doc->tax_breakdown = $parsed['tax_breakdown'];
        $doc->lines = $parsed['lines'];

        $customer_match = $doc->customer !== null
            && (string) $doc->customer === (string) $company->identification_number;

        return [
            'success' => true,
            'message' => 'Datos del documento obtenidos satisfactoriamente.',
            'data' => $doc,
            'customer_match' => $customer_match,
            'root' => $parsed['doc_type'],
            'xml' => $xml,
        ];
    }

    /**
     * Extraer el primer texto legible de la respuesta DIAN.
     *
     * El cliente SOAP (xmlToObject) devuelve el nodo Message como string solo si
     * es texto simple; si trae atributos, viene vacío o es un Fault, llega como
     * stdClass anidado. Se recorre en profundidad buscando el primer string no
     * vacío, ignorando '_attributes'.
     *
     * @param  mixed $node
     * @return string|null
     */
    protected function extractDianText($node): ?string
    {
        if (is_string($node)) {
            $node = trim($node);
            return $node !== '' ? $node : null;
        }

        if (is_object($node)) {
            $node = (array) $node;
        }

        if (is_array($node)) {
            // Preferir las claves que suelen llevar el mensaje (incluido Fault SOAP),
            // para no devolver códigos como "s:Sender" antes que la razón.
            foreach (['Reason', 'Text', 'faultstring', 'Message', '_value', 'string'] as $preferred) {
                if (array_key_exists($preferred, $node)) {
                    $text = $this->extractDianText($node[$preferred]);
                    if ($text !== null) {
                        return $text;
                    }
                }
            }

            foreach ($node as $key => $value) {
                if ($key === '_attributes') {
                    continue;
                }
                $text = $this->extractDianText($value);
                if ($text !== null) {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Persistir un documento parseado en received_documents.
     *
     * @param  \stdClass $invoice_doc
     * @return \App\ReceivedDocument
     */
    protected function persistReceivedDocument(\stdClass $invoice_doc): ReceivedDocument
    {
        $receivedDoc = new ReceivedDocument();
        $receivedDoc->identification_number = $invoice_doc->identification_number;
        $receivedDoc->dv = $invoice_doc->dv;
        $receivedDoc->name_seller = $invoice_doc->name_seller;
        $receivedDoc->state_document_id = $invoice_doc->state_document_id;
        $receivedDoc->type_document_id = $invoice_doc->type_document_id;
        $receivedDoc->customer = $invoice_doc->customer;
        $receivedDoc->customer_name = $invoice_doc->customer_name ?? '';
        $receivedDoc->prefix = $invoice_doc->prefix;
        $receivedDoc->number = $invoice_doc->number;
        $receivedDoc->xml = "no-attached-document";
        $receivedDoc->cufe = $invoice_doc->cufe;
        $receivedDoc->date_issue = $invoice_doc->date_issue;
        $receivedDoc->sale = $invoice_doc->sale;
        $receivedDoc->total_discount = $invoice_doc->total_discount;
        $receivedDoc->subtotal = $invoice_doc->subtotal;
        $receivedDoc->total_tax = $invoice_doc->total_tax;
        $receivedDoc->total = $invoice_doc->total;
        $receivedDoc->ambient_id = $invoice_doc->ambient_id;
        $receivedDoc->pdf = "no-attached-document";
        $receivedDoc->acu_recibo = 0;
        $receivedDoc->rec_bienes = 0;
        $receivedDoc->aceptacion = 0;
        $receivedDoc->rechazo = 0;
        $receivedDoc->save();

        return $receivedDoc;
    }
}
