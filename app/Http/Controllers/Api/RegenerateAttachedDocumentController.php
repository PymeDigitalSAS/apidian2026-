<?php

namespace App\Http\Controllers\Api;

use App\User;
use App\Company;
use App\Resolution;
use App\TypeDocument;
use App\Municipality;
use App\Document;
use App\DocumentPayroll;
use Illuminate\Http\Request;
use App\Traits\DocumentTrait;
use App\Http\Controllers\Controller;
use ubl21dian\XAdES\SignAttachedDocument;
use ubl21dian\Templates\SOAP\GetXmlByDocumentKey;
use App\Services\StorageService;

/**
 * Regenera el AttachedDocument (XML adjunto / "contenedor" code 89) de un
 * documento ya validado por la DIAN, reutilizando los archivos que el sistema
 * ya guardó al momento de la emisión:
 *
 *   - {PREFIJO}-{n}.xml  -> XML firmado del documento (signedxml)
 *   - Rpta{PREFIJO sin S}-{n}.xml -> respuesta de la DIAN, de donde se extrae el
 *                                    ApplicationResponse (XmlBase64Bytes) y el XmlFileName.
 *
 * Aplica a todos los documentos que generan adjunto:
 *   - Factura electrónica         (FES-  / RptaFE-)
 *   - Nota crédito                (NCS-  / RptaNC-)
 *   - Nota débito                 (NDS-  / RptaND-)
 *   - Documento soporte           (DSS-  / RptaDS-)
 *   - Nota ajuste doc. soporte    (NDSNS-/ RptaNDSN-)
 *
 * Las nóminas electrónicas (NominaIndividual / Ajuste) NO generan AttachedDocument,
 * por lo que se rechazan con un mensaje claro.
 *
 * No reconsulta a la DIAN: regenera exactamente el mismo adjunto que se generó
 * originalmente. Pensado para los casos en que el cliente perdió el archivo y
 * necesita volver a obtenerlo.
 */
class RegenerateAttachedDocumentController extends Controller
{
    use DocumentTrait;

    /**
     * Regenera el AttachedDocument y lo devuelve en base64.
     *
     * Body (JSON): { "cufe": "..." }  o  { "prefix": "...", "number": "..." }
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function regenerate(Request $request)
    {
        try {
            // Empresa autenticada (emisor)
            $user = auth()->user();
            $company = $user->company;

            // 1. Ubicar el documento (validado, state 1)
            $invoice_doc = $this->findDocument($request, $company);
            if (!$invoice_doc) {
                // Las nóminas (NominaIndividual / Ajuste) NO generan AttachedDocument,
                // así que damos un mensaje claro si el identificador corresponde a una.
                if ($this->isPayrollDocument($request, $company)) {
                    return [
                        'success' => false,
                        'message' => 'Las nóminas electrónicas no generan AttachedDocument, por lo tanto no es posible regenerarlo. Solo facturas, notas crédito/débito y documentos soporte tienen adjunto.',
                    ];
                }
                return [
                    'success' => false,
                    'message' => 'Documento no encontrado. Envíe el CUFE, o el prefijo y número de un documento validado por la DIAN.',
                ];
            }

            // 2. Prefijo, consecutivo y rutas de los archivos guardados.
            //    Document->xml = "{PREFIJO}-{consecutivo}.xml" (FES, NCS, NDS, DSS, NDSNS...).
            //    El prefijo del XML firmado es el de la respuesta DIAN + "S" (FES->FE,
            //    NCS->NC, NDS->ND, DSS->DS, NDSNS->NDSN), así que la respuesta es
            //    "Rpta{PREFIJO sin la S final}-{consecutivo}.xml".
            if (empty($invoice_doc->xml)) {
                return [
                    'success' => false,
                    'message' => 'El documento no tiene registrado el nombre del XML firmado (campo xml).',
                ];
            }
            $basename = str_replace('.xml', '', $invoice_doc->xml);   // ej. "NCS-994900180"
            $dashPos = strpos($basename, '-');
            if ($dashPos === false) {
                return [
                    'success' => false,
                    'message' => "El nombre del XML firmado ({$invoice_doc->xml}) no tiene el formato esperado PREFIJO-CONSECUTIVO.xml.",
                ];
            }
            $signedPrefix = substr($basename, 0, $dashPos);            // ej. "NCS"
            $consecutive = substr($basename, $dashPos + 1);           // ej. "994900180"
            // Prefijo de la respuesta DIAN: quita SOLO la "S" final del prefijo firmado.
            $rptaPrefix = (substr($signedPrefix, -1) === 'S')
                ? substr($signedPrefix, 0, -1)
                : $signedPrefix;
            $basePath = "public/{$company->identification_number}";

            // OJO: si existe otro documento con el mismo prefijo y número (p. ej.
            // un reenvío que quedó rechazado), los archivos FES/Rpta en disco
            // fueron sobrescritos por ese último envío y ya no corresponden a
            // este documento (o traen el XmlBase64Bytes vacío). Por eso cada
            // fuente se valida contra el CUFE y, si falla, se recupera de la BD
            // (response_api / response_dian) o de la DIAN (GetXmlByDocumentKey).
            $cufecude = $invoice_doc->cufe;

            // 3. XML firmado (signedxml) del documento (factura, nota, doc. soporte...)
            $signedxml = null;
            $signedPath = "{$basePath}/{$invoice_doc->xml}";
            if (StorageService::existsAuto($signedPath)) {
                $candidate = StorageService::getAuto($signedPath);
                if ($this->belongsToDocument($candidate, $cufecude))
                    $signedxml = $candidate;
            }
            if (!$signedxml)
                $signedxml = $this->signedXmlFromDb($invoice_doc);

            // 4. Respuesta de la DIAN (Rpta{PREFIJO}) -> ApplicationResponse + nombre del adjunto
            $xmlFileName = null;
            $appresponsexml = null;
            $rptaFile = "Rpta{$rptaPrefix}-{$consecutive}.xml";
            $rptaPath = "{$basePath}/{$rptaFile}";
            if (StorageService::existsAuto($rptaPath)) {
                $rptaContent = StorageService::getAuto($rptaPath);
                $xmlFileNameTag = $this->getTag($rptaContent, 'XmlFileName');
                $appResponseTag = $this->getTag($rptaContent, 'XmlBase64Bytes');
                if ($xmlFileNameTag && $appResponseTag && trim($appResponseTag->nodeValue) !== '') {
                    $decoded = base64_decode($appResponseTag->nodeValue);
                    if ($this->belongsToDocument($decoded, $cufecude)) {
                        $xmlFileName = $xmlFileNameTag->nodeValue;
                        $appresponsexml = $decoded;
                    }
                }
            }
            if (!$appresponsexml)
                list($xmlFileName, $appresponsexml) = $this->applicationResponseFromDb($invoice_doc);

            // Último recurso: pedir a la DIAN el AttachedDocument por CUFE y
            // extraer de ahí lo que siga faltando (típico en notas y doc.
            // soporte, que no guardan respaldo en BD).
            if (!$signedxml || !$appresponsexml) {
                list($dianSigned, $dianAppResponse) = $this->fetchFromDian($company, $cufecude, $invoice_doc->type_document_id);
                $signedxml = $signedxml ?: $dianSigned;
                $appresponsexml = $appresponsexml ?: $dianAppResponse;
            }

            if (!$signedxml || !$appresponsexml) {
                return [
                    'success' => false,
                    'message' => "Los archivos {$invoice_doc->xml} / {$rptaFile} no corresponden a este documento o están incompletos (probablemente fueron sobrescritos por otro envío con el mismo prefijo y número), y no fue posible recuperarlos de la base de datos ni de la DIAN. No es posible regenerar el adjunto.",
                ];
            }

            // 5. Fecha y hora de validación desde el ApplicationResponse
            $ar = new \DOMDocument;
            $ar->loadXML($appresponsexml);
            $fechavalidacion = $ar->documentElement->getElementsByTagName('IssueDate')->item(0)->nodeValue;
            $horavalidacion = $ar->documentElement->getElementsByTagName('IssueTime')->item(0)->nodeValue;

            // 6. Tipo y número de documento desde el XML firmado
            if (strpos($signedxml, "</Invoice>") > 0)
                $td = '/Invoice';
            else if (strpos($signedxml, "</CreditNote>") > 0)
                $td = '/CreditNote';
            else
                $td = '/DebitNote';
            $document_number = $this->ValueXML($signedxml, $td . "/cbc:ID/");

            // 7. Reconstruir el receptor (customer) tal como en la emisión original,
            //    a partir del request_api guardado.
            $requestData = json_decode($invoice_doc->request_api ?? '[]', true) ?: [];
            $customer = $this->buildCustomer($requestData);

            // 8. Resolución: la plantilla 89 usa document_number (id con prefijo, ej.
            //    SETP994900180) y, como respaldo, next_consecutive.
            $resolution = new Resolution();
            $resolution->next_consecutive = $consecutive;
            $resolution->document_number = $document_number;

            // Fecha/hora del contenedor: usar la fecha/hora de validación de la DIAN
            // (estable y fiel al original) en vez de la fecha de regeneración.
            // $horavalidacion viene con offset (ej. "16:21:58-05:00"); la plantilla 89
            // ya agrega "-05:00", así que se lo quitamos para no duplicarlo.
            $date = $fechavalidacion;
            $time = preg_replace('/[+-]\d{2}:\d{2}$/', '', $horavalidacion);

            // 9. Tipo de documento AttachedDocument (code 89)
            $typeDocument = TypeDocument::findOrFail(7);

            // 10. Nombre del adjunto: mismo que generó la emisión (fv/nc/nd... -> ad).
            //     Si no se pudo recuperar el XmlFileName (adjunto reconstruido
            //     desde la DIAN), se usa un nombre equivalente con el consecutivo.
            if ($xmlFileName)
                $filename = str_replace('ttr', 'ad', str_replace('pos', 'ad', str_replace('ads', 'ad',
                            str_replace('dse', 'ad', str_replace('ni', 'ad', str_replace('nd', 'ad',
                            str_replace('nc', 'ad', str_replace('fv', 'ad', $xmlFileName))))))));
            else
                $filename = 'ad-' . strtolower($rptaPrefix) . '-' . $consecutive;
            if (!empty($requestData['atacheddocument_name_prefix']))
                $filename = $requestData['atacheddocument_name_prefix'] . $filename;

            // 11. Generar, firmar y guardar el AttachedDocument
            $attacheddocument = $this->createXML(compact(
                'user', 'company', 'customer', 'resolution', 'typeDocument',
                'cufecude', 'signedxml', 'appresponsexml', 'fechavalidacion',
                'horavalidacion', 'document_number', 'date', 'time'
            ));

            $signAttachedDocument = new SignAttachedDocument($company->certificate->path, $company->certificate->password);
            $signAttachedDocument->GuardarEn = StorageService::tempPath("{$basePath}/{$filename}.xml");
            $at = $signAttachedDocument->sign($attacheddocument)->xml;

            $file = fopen(StorageService::tempPath("{$basePath}/{$filename}.xml"), "w");
            fwrite($file, $at);
            fclose($file);

            // Subir a S3 si aplica
            StorageService::uploadBatchIfS3(["{$basePath}/{$filename}.xml"]);

            return [
                'success' => true,
                'message' => "AttachedDocument regenerado con éxito para el documento {$invoice_doc->prefix}{$invoice_doc->number}.",
                'urlinvoiceattached' => "{$filename}.xml",
                'attacheddocument' => base64_encode($at),
            ];
        } catch (\Exception $e) {
            \Log::error('RegenerateAttachedDocument: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Ubica el documento validado a partir del CUFE o del prefijo+número.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Company $company
     * @return \App\Document|null
     */
    protected function findDocument($request, $company)
    {
        if ($request->cufe) {
            $doc = Document::where('identification_number', $company->identification_number)
                           ->where('cufe', $request->cufe)
                           ->where('state_document_id', 1)
                           ->first();
            if ($doc)
                return $doc;
        }

        if ($request->prefix && $request->number) {
            return Document::where('identification_number', $company->identification_number)
                           ->where('prefix', $request->prefix)
                           ->where('number', $request->number)
                           ->where('state_document_id', 1)
                           ->first();
        }

        return null;
    }

    /**
     * Indica si el identificador (CUFE/CUNE o prefijo+número) corresponde a una
     * nómina electrónica. Se usa solo para dar un mensaje claro, ya que las
     * nóminas no generan AttachedDocument.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Company $company
     * @return bool
     */
    protected function isPayrollDocument($request, $company)
    {
        if ($request->cufe) {
            $exists = DocumentPayroll::where('identification_number', $company->identification_number)
                                     ->where('cune', $request->cufe)
                                     ->exists();
            if ($exists)
                return true;
        }

        if ($request->prefix && $request->number) {
            return DocumentPayroll::where('identification_number', $company->identification_number)
                                  ->where('prefix', $request->prefix)
                                  ->where('consecutive', $request->number)
                                  ->exists();
        }

        return false;
    }

    /**
     * Reconstruye el receptor (customer) a partir del request original guardado,
     * idéntico a como lo arma el flujo de emisión.
     *
     * @param array $requestData
     * @return \App\User|null
     */
    protected function buildCustomer(array $requestData)
    {
        if (empty($requestData['customer']))
            return null;

        $customerAll = collect($requestData['customer']);
        if (isset($customerAll['municipality_id_fact']))
            $customerAll['municipality_id'] = Municipality::where('codefacturador', $customerAll['municipality_id_fact'])->first();
        $customerAll = $this->resolveCustomerLocation($customerAll);

        $customer = new User($customerAll->toArray());
        $customer->company = new Company($customerAll->toArray());

        return $customer;
    }

    /**
     * Verifica que un XML pertenezca al documento comparando el CUFE/CUDE.
     * Protege contra archivos sobrescritos por otro documento con el mismo
     * prefijo y número (p. ej. un reenvío que quedó rechazado).
     *
     * @param string|false|null $xml
     * @param string|null $cufe
     * @return bool
     */
    protected function belongsToDocument($xml, $cufe)
    {
        if (empty($xml))
            return false;

        // Sin CUFE registrado no hay contra qué validar; se acepta el archivo.
        if (empty($cufe))
            return true;

        return stripos($xml, $cufe) !== false;
    }

    /**
     * XML firmado desde el response_api guardado en la BD (clave invoicexml).
     * Solo las facturas lo guardan, y únicamente si la configuración
     * save_response_dian_to_db está activa.
     *
     * @param \App\Document $invoice_doc
     * @return string|null
     */
    protected function signedXmlFromDb($invoice_doc)
    {
        $response = json_decode($invoice_doc->response_api ?? '', true);
        if (!is_array($response) || empty($response['invoicexml']) || !is_string($response['invoicexml']))
            return null;

        $xml = base64_decode($response['invoicexml']);

        return $this->belongsToDocument($xml, $invoice_doc->cufe) ? $xml : null;
    }

    /**
     * ApplicationResponse (XmlFileName + XML decodificado) desde el
     * response_dian guardado en la BD para este documento.
     *
     * @param \App\Document $invoice_doc
     * @return array [xmlFileName|null, appresponsexml|null]
     */
    protected function applicationResponseFromDb($invoice_doc)
    {
        $response = json_decode($invoice_doc->response_dian ?? '', true);
        if (!is_array($response))
            return [null, null];

        $xmlFileName = $this->findKeyRecursive($response, 'XmlFileName');
        $xmlBase64 = $this->findKeyRecursive($response, 'XmlBase64Bytes');
        if (!is_string($xmlBase64) || trim($xmlBase64) === '')
            return [null, null];

        $decoded = base64_decode($xmlBase64);
        if (!$this->belongsToDocument($decoded, $invoice_doc->cufe))
            return [null, null];

        return [is_string($xmlFileName) ? $xmlFileName : null, $decoded];
    }

    /**
     * Busca una clave en cualquier nivel del arreglo. La ruta exacta dentro del
     * SOAP varía según el servicio con que se envió el documento (SendBillSync,
     * SendTestSetAsync, GetStatus...), por eso se busca de forma recursiva.
     *
     * @param array $data
     * @param string $key
     * @return mixed|null
     */
    protected function findKeyRecursive(array $data, $key)
    {
        if (array_key_exists($key, $data))
            return $data[$key];

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findKeyRecursive($value, $key);
                if ($found !== null)
                    return $found;
            }
        }

        return null;
    }

    /**
     * Último recurso: pide a la DIAN el AttachedDocument por CUFE
     * (GetXmlByDocumentKey, con el certificado de la empresa) y extrae el XML
     * firmado y el ApplicationResponse de los nodos Description (CDATA).
     *
     * @param \App\Company $company
     * @param string|null $cufe
     * @param int|null $type_document_id
     * @return array [signedxml|null, appresponsexml|null]
     */
    protected function fetchFromDian($company, $cufe, $type_document_id = null)
    {
        if (empty($cufe))
            return [null, null];

        try {
            // Notas y doc. soporte pueden estar en un ambiente distinto al de facturacion:
            // la consulta tiene que ir a la URL del tipo de documento, no siempre a la de FE.
            $dian_url = is_null($type_document_id)
                ? $company->software->url
                : $company->software->urlForTypeDocument($type_document_id);
            $getXml = new GetXmlByDocumentKey($company->certificate->path, $company->certificate->password, $dian_url);
            $getXml->trackId = $cufe;
            $r = $getXml->signToSend()->getResponseToObject();

            $result = $r->Envelope->Body->GetXmlByDocumentKeyResponse->GetXmlByDocumentKeyResult ?? null;
            $bytes = $result->XmlBytesBase64 ?? null;
            if (($result->Code ?? null) != '100' || empty($bytes))
                return [null, null];

            // XmlBytesBase64 puede venir como string o como objeto {_value: ...};
            // base64_decode no estricto ignora las comillas del json_encode.
            $attached = base64_decode(is_string($bytes) ? $bytes : json_encode($bytes));
            if (empty($attached))
                return [null, null];

            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $ok = $dom->loadXML($attached, LIBXML_NOCDATA);
            libxml_clear_errors();
            if (!$ok)
                return [null, null];

            $signedxml = null;
            $appresponsexml = null;
            foreach ($dom->getElementsByTagName('Description') as $desc) {
                $inner = trim((string) $desc->nodeValue);
                if (!$signedxml && preg_match('/<(Invoice|CreditNote|DebitNote)[\s>]/', $inner))
                    $signedxml = $inner;
                elseif (!$appresponsexml && preg_match('/<ApplicationResponse[\s>]/', $inner))
                    $appresponsexml = $inner;
            }

            return [$signedxml, $appresponsexml];
        } catch (\Throwable $e) {
            \Log::warning('RegenerateAttachedDocument: fallback GetXmlByDocumentKey falló: ' . $e->getMessage());
            return [null, null];
        }
    }
}
