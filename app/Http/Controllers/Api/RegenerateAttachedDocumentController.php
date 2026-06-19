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

            // 3. XML firmado (signedxml) del documento (factura, nota, doc. soporte...)
            $signedPath = "{$basePath}/{$invoice_doc->xml}";
            if (!StorageService::existsAuto($signedPath)) {
                return [
                    'success' => false,
                    'message' => "No se encontró el XML firmado del documento ({$invoice_doc->xml}). No es posible regenerar el adjunto.",
                ];
            }
            $signedxml = StorageService::getAuto($signedPath);

            // 4. Respuesta de la DIAN (Rpta{PREFIJO}) -> ApplicationResponse + nombre del adjunto
            $rptaFile = "Rpta{$rptaPrefix}-{$consecutive}.xml";
            $rptaPath = "{$basePath}/{$rptaFile}";
            if (!StorageService::existsAuto($rptaPath)) {
                return [
                    'success' => false,
                    'message' => "No se encontró la respuesta de la DIAN almacenada ({$rptaFile}). No es posible regenerar el adjunto.",
                ];
            }
            $rptaContent = StorageService::getAuto($rptaPath);

            $xmlFileNameTag = $this->getTag($rptaContent, 'XmlFileName');
            $appResponseTag = $this->getTag($rptaContent, 'XmlBase64Bytes');
            if (!$xmlFileNameTag || !$appResponseTag) {
                return [
                    'success' => false,
                    'message' => 'La respuesta de la DIAN almacenada no contiene el ApplicationResponse esperado (XmlFileName / XmlBase64Bytes).',
                ];
            }
            $xmlFileName = $xmlFileNameTag->nodeValue;
            $appresponsexml = base64_decode($appResponseTag->nodeValue);

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

            // 9. CUFE/CUDE y tipo de documento AttachedDocument (code 89)
            $cufecude = $invoice_doc->cufe;
            $typeDocument = TypeDocument::findOrFail(7);

            // 10. Nombre del adjunto: mismo que generó la emisión (fv/nc/nd... -> ad)
            $filename = str_replace('ttr', 'ad', str_replace('pos', 'ad', str_replace('ads', 'ad',
                        str_replace('dse', 'ad', str_replace('ni', 'ad', str_replace('nd', 'ad',
                        str_replace('nc', 'ad', str_replace('fv', 'ad', $xmlFileName))))))));
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
}
