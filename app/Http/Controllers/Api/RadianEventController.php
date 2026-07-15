<?php

namespace App\Http\Controllers\Api;

use App\ReceivedDocument;
use Illuminate\Http\Request;
use App\Traits\DocumentTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\XmlDocumentRequest;
use App\Services\CufeReportExporter;
use App\Services\UblDocumentParser;
use Carbon\Carbon;

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
                    } else {
                        $row['dian'] = $built['data'];
                        $row['root'] = $built['root'];
                        $row['enrich_status'] = $built['customer_match'] ? 'ok' : 'mismatch';
                        $row['enrich_message'] = $built['customer_match']
                            ? 'OK'
                            : 'El adquiriente del documento no corresponde al NIT ' . $company->identification_number . '.';
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
     * @return array{success:bool, message:string, data:?\stdClass, customer_match:bool, root:?string}
     */
    protected function buildDocumentByCufe($company, $cufe): array
    {
        // Consultar XML en la DIAN por CUFE (verificarEstadoDIAN y verify_certificate
        // se ejecutan dentro de XmlDocumentController).
        $xmlDIAN = new XmlDocumentController();
        $send = ['is_event' => true];
        $r = $xmlDIAN->document(new XmlDocumentRequest($send), $cufe);

        if (!isset($r['success']) || !$r['success']) {
            $msg = 'No se pudo obtener el documento de la DIAN.';
            if (isset($r['ResponseDian']) && is_string($r['ResponseDian'])) {
                $msg = $r['ResponseDian'];
            } elseif (isset($r['message'])) {
                $msg = $r['message'];
            }

            return [
                'success' => false,
                'message' => $msg,
                'data' => null,
                'customer_match' => false,
                'root' => null,
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
        ];
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
