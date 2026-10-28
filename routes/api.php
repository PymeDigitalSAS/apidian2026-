<?php

use App\Services\StorageService;

// Integración optativa y autenticada con Perfex; sus acciones se habilitan en config/erp.php.
require __DIR__ . '/erp.php';

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// UBL 2.1
Route::prefix('/ubl2.1')->group(function () {

    // Configuration
    Route::prefix('/config')->middleware(['check.api.register'])->group(function () {
        Route::get('/query/{nit}', 'Api\ConfigurationController@queryCompany');
        Route::post('/{nit}/{dv?}', 'Api\ConfigurationController@store');
        Route::post('/delete/{nit}/{email}', 'Api\ConfigurationController@destroyCompany');
    });

    Route::get('/listing', 'Api\ListingController@all');

    // Plan
    Route::put('/plan', 'Api\ConfigurationController@storePlan');
    Route::get('/plan/query/{id?}', 'Api\ConfigurationController@queryPlan');
    Route::get('/plan/queryusersbyplan/{id}', 'Api\ConfigurationController@queryUsersByPlan');

    // Administrador
    Route::put('/administrator', 'Api\ConfigurationController@storeAdministrator');
    Route::get('/administrator/query/{nit?}', 'Api\ConfigurationController@queryAdministrator');
    Route::get('/administrator/queryusersbyadmin/{nit}', 'Api\ConfigurationController@queryUsersByAdministrator');

    // Sign Document XML
    Route::prefix('/signdocument')->group(function () {
        Route::post('/', 'Api\SignDocumentController@signdocument');
    });

    // Send Document XML
    Route::prefix('/senddocument')->group(function () {
        Route::post('/', 'Api\SendDocumentController@senddocument');
    });

    Route::prefix('/statusdocument')->group(function () {
        Route::post('/', 'Api\StatusDocumentController@statusdocument');
    });

    Route::prefix('/statuszip')->group(function () {
        Route::post('/', 'Api\StatusZipController@statuszip');
    });

    // Consulta stateless de XMLs por CUFE (cert .p12 + password + cufes -> XMLs base64).
    // No requiere empresa; protegido con header X-Api-Key (XML_QUERY_API_KEY del .env).
    Route::post('/xml/by-cufes', 'Api\XmlByCufeController@byCufes');

    Route::get('/currencies', 'Api\TrmController@getAllCurrencies');
    Route::get('/trm', 'Api\TrmController@getHtmlTrm');
});

// ============================================================
// Orquestador "Por CUFEs" — el API hace TODO: obtiene los CUFEs vía el worker
// Node (navegador, EXTRACTOR_URL), consulta los XML con el certificado y arma el
// reporte. Stateless (cert en la petición), protegido con X-Api-Key
// (XML_QUERY_API_KEY). Rutas espejo del extractor: el cliente solo cambia la URL base.
// ============================================================
Route::post('/cufes', 'Api\CufesReportController@cufes');
Route::post('/xml-by-cufes', 'Api\CufesReportController@byToken');

// Flujo completo por TOKEN (sin certificado), espejo del /api/scrape del worker:
// descarga los documentos del listado DIAN y los parsea. Devuelve { log, data }
// con la misma estructura del extractor. Soporta async:true + polling /api/jobs/{id}.
Route::post('/scrape', 'Api\CufesReportController@scrape');
Route::get('/jobs/{id}', 'Api\CufesReportController@job');

Route::middleware(['auth:api', 'storage.context'])->group(function () {

    Route::get('reload-pdf/{identification}/{file}/{cufe}', 'Api\DownloadController@reloadPdf');

    Route::get('/templates', 'Api\ConfigurationController@getTemplates');
    Route::put('/templates', 'Api\ConfigurationController@updateTemplate');

    Route::post('process-seller-document-reception', 'Api\RadianEventController@processSellerDocumentReception');

    // Load document data from DIAN by CUFE without sending event
    Route::post('load-document-by-cufe', 'Api\RadianEventController@loadDocumentByCufe');

    // Reporte por CUFEs: enriquecer un lote de CUFEs con datos de la DIAN y
    // generar el reporte descargable (XLSX / JSON).
    Route::post('cufes/enrich', 'Api\RadianEventController@enrichCufes');
    Route::post('cufes/report', 'Api\RadianEventController@cufesReport');

    // Extracción por token AUTENTICADA (token de empresa -> certificado propio).
    //  - stream: UN endpoint que emite el log en vivo (SSE) + JSON final (como el extractor).
    //  - async : POST devuelve jobId; GET consulta avance/resultado (datos procesados).
    Route::post('extractor/company/stream', 'Api\RadianEventController@streamCompanyExtraction');
    Route::post('extractor/company', 'Api\RadianEventController@startCompanyExtraction');
    Route::get('extractor/company/jobs/{id}', 'Api\RadianEventController@companyExtractionStatus');

    Route::get('/customer/{document}/{nit}', 'Api\CustomerController@getAcquirer');

    Route::prefix('/table')->group(function(){
        Route::get('/health_type_document_identifications', 'Api\ConfigurationController@table_health_type_document_identifications');
        Route::get('/health_type_users', 'Api\ConfigurationController@table_health_type_users');
        Route::get('/health_contracting_payment_methods', 'Api\ConfigurationController@table_health_contracting_payment_methods');
        Route::get('/health_coverages', 'Api\ConfigurationController@table_health_coverages');
        Route::get('/health_invoice_without_contracts', 'Api\ConfigurationController@table_health_invoice_without_contracts');
        Route::get('/resolutions/{identification_number?}/{type_id?}', 'Api\ConfigurationController@table_resolutions');
        Route::get('/municipality-code-by-facturador/{codefacturador}', 'Api\MunicipalityController@codeByFacturador');
        Route::post('/municipality-codes-by-facturador', 'Api\MunicipalityController@codesByFacturador');
        Route::get('/payment-methods', 'Api\PaymentController@getPaymentMethods');
        Route::get('/payment-forms', 'Api\PaymentController@getPaymentForms');
        Route::post('/payment-methods', 'Api\PaymentController@store');
        Route::put('/payment-methods/{id}', 'Api\PaymentController@update');
        Route::delete('/payment-methods/{id}', 'Api\PaymentController@destroy');
        Route::delete('/payment-methods', 'Api\PaymentController@destroyByFields');

        Route::get('/items', 'Api\ItemController@records');
    });

    Route::prefix('/rips')->group(function () {
        Route::post('/configuration', 'Api\RipsConfigurationController@setConfiguration');
        Route::post('/', 'Api\RipsController@processRips');
        Route::apiResource('/service-providers', 'Api\RipsServiceProviderController');
        Route::get('patients/tables', 'Api\RipsPatientController@getTables');
        Route::apiResource('/patients', 'Api\RipsPatientController');
        Route::apiResource('/appointments', 'Api\RipsAppointmentController');
        Route::get('services/tables', 'Api\RipsServiceController@getTables');
        Route::get('services/procedures', 'Api\RipsServiceController@getProcedureCodes');
        Route::get('services/cies', 'Api\RipsServiceController@getCies');
        Route::post('documents', 'Api\RipsDocumentController@store');
        Route::post('documents/generate/{id}', 'Api\RipsDocumentController@generateRips');
    });

    Route::prefix('/whatsapp-config')->group(function () {
        Route::put('/', 'Api\WhatsappConfigController@store');
        Route::get('/', 'Api\WhatsappConfigController@show');
        Route::post('/send-message', 'Api\WhatsappConfigController@sendMessage');
        Route::post('/send-message-pdf', 'Api\WhatsappConfigController@sendMessageWithPDF');
    });

    // UBL 2.1
    Route::prefix('/ubl2.1')->group(function () {
        // Xml Document
        Route::prefix('/xml')->group(function () {
	        Route::post('/document/{trackId}/{GuardarEn?}', 'Api\XmlDocumentController@document');
        });

        // Configuration email
        Route::get('/emailconfig', 'Api\ConfigurationController@emailconfig');

        // Plan info
        Route::get('/plan/infoplanuser', 'Api\ConfigurationController@infoPlanUser');

        // Join PDFs
        Route::post('/join-pdfs', 'Api\MiscelaneousController@joinPDFs');

        Route::get('/name-by-nit/{nit}', 'Api\MiscelaneousController@nameByNit');
        Route::get('/SearchCompany/{nit}', 'Api\MiscelaneousController@SearchCompany');

        // Register Customer
        Route::put('/register-update-customer', 'Api\ConfigurationController@RegCustomer');

        // Certificate End Date
        Route::put('/certificate-end-date', 'Api\ConfigurationController@CertificateEndDate');

        // Check OpenSSL Configuration
        Route::get('/check-openssl-config', 'Api\ConfigurationController@checkOpenSSLConfig');

        // Configuration
        Route::prefix('/config')->group(function () {
            Route::put('/software', 'Api\ConfigurationController@storeSoftware');
            Route::put('/softwarepayroll', 'Api\ConfigurationController@storeSoftware');
            Route::put('/softwareeqdocs', 'Api\ConfigurationController@storeSoftware');
            Route::put('/softwaresupportdocument', 'Api\ConfigurationController@storeSoftware');
            Route::put('/certificate', 'Api\ConfigurationController@storeCertificate');
            Route::get('/certificate', 'Api\ConfigurationController@downloadCertificate');
            Route::put('/resolution', 'Api\ConfigurationController@storeResolution');
            Route::put('/environment', 'Api\ConfigurationController@storeEnvironment');
            Route::put('/logo', 'Api\ConfigurationController@storeLogo');
            Route::put('/generateddocuments', 'Api\ConfigurationController@storeInitialDocument');
        });

        Route::prefix('/delete')->middleware(['check.api.register'])->group(function () {
            Route::post('/company/{nit}/{dv}', 'Api\ConfigurationController@deleteCompany');
        });

        // Next Consecutive
        Route::post('/next-consecutive', 'Api\MiscelaneousController@NextConsecutive');

        // Regenerate PDF
        Route::prefix('/regeneratepdf')->group(function () {
            Route::post('/', 'Api\RegeneratePDFController@document_request');
            Route::post('/{prefix}/{number}/{cufe}', 'Api\RegeneratePDFController@document_url');
        });

        // Regenerate Attached Document (XML adjunto code 89)
        Route::prefix('/regenerate-attached-document')->group(function () {
            Route::post('/', 'Api\RegenerateAttachedDocumentController@regenerate');
        });

        // Certificate Listing
        Route::get('/certificates-listing', 'Api\ConfigurationController@certificates_listing');
        Route::get('/certificates-listing/{company_identification_number}', 'Api\ConfigurationController@certificates_listing');

        // Equivalent Documents
        Route::prefix('/eqdoc')->group(function () {
            Route::post('/preeliminar-view', 'Api\EqDocController@preeliminarview');
            Route::post('/{testSetId}', 'Api\EqDocController@testSetStore');
            Route::post('/', 'Api\EqDocController@store');
        });

        // Invoice
        Route::prefix('/invoice')->group(function () {
            Route::post('/preeliminar-view', 'Api\InvoiceController@preeliminarview');
            Route::post('/{testSetId}', 'Api\InvoiceController@testSetStore');
            Route::post('/', 'Api\InvoiceController@store');
            Route::get('/current_number/{type}/{prefix?}/{ignore_state_document_id?}', 'Api\InvoiceController@currentNumber');
            Route::get('/state_document/{type}/{number}', 'Api\InvoiceController@changestateDocument');
        });

        // Document lookup (conciliacion): todas las filas por tipo + prefijo + numero
        Route::get('/document-lookup/{type_document_id}/{prefix}/{number}', 'Api\DocumentLookupController@byPrefixNumber');

        // Export Invoice
        Route::prefix('/invoice-export')->group(function () {
            Route::post('/{testSetId}', 'Api\InvoiceExportController@testSetStore');
            Route::post('/', 'Api\InvoiceExportController@store');
        });

        // Contingency Invoice type 3
        Route::prefix('/invoice-contingency')->group(function () {
            Route::post('/{testSetId}', 'Api\InvoiceContingencyController@testSetStore');
            Route::post('/', 'Api\InvoiceContingencyController@store');
        });

        // Contingency Invoice type 4
        Route::prefix('/invoice-contingency-4')->group(function () {
            Route::post('/send_pendings/{prefix?}/{number?}', 'Api\InvoiceContingencyController@send_pendings');
            Route::post('/', 'Api\InvoiceContingencyController@store_type_4');
        });

        // AUI Invoice
        Route::prefix('/invoice-aiu')->group(function () {
            Route::post('/{testSetId}', 'Api\InvoiceAIUController@testSetStore');
            Route::post('/', 'Api\InvoiceAIUController@store');
        });

        // Mandate Invoice
        Route::prefix('/invoice-mandate')->group(function () {
            Route::post('/{testSetId}', 'Api\InvoiceMandateController@testSetStore');
            Route::post('/', 'Api\InvoiceMandateController@store');
        });

        // Transport Invoice
        Route::prefix('/invoice-transport')->group(function () {
            Route::post('/{testSetId}', 'Api\InvoiceTransportController@testSetStore');
            Route::post('/', 'Api\InvoiceTransportController@store');
        });

        // Credit Notes
        Route::prefix('/credit-note')->group(function () {
            Route::post('/{testSetId}', 'Api\CreditNoteController@testSetStore');
            Route::post('/', 'Api\CreditNoteController@store');
        });

        // Debit Notes
        Route::prefix('/debit-note')->group(function () {
            Route::post('/{testSetId}', 'Api\DebitNoteController@testSetStore');
            Route::post('/', 'Api\DebitNoteController@store');
        });

        // Support Document
        Route::prefix('/support-document')->group(function () {
            Route::post('/preeliminar-view', 'Api\SupportDocumentController@preeliminarview');
            Route::post('/{testSetId}', 'Api\SupportDocumentController@testSetStore');
            Route::post('/', 'Api\SupportDocumentController@store');
        });

        // Support Document Credit Notes
        Route::prefix('/sd-credit-note')->group(function () {
            Route::post('/{testSetId}', 'Api\sdCreditNoteController@testSetStore');
            Route::post('/', 'Api\sdCreditNoteController@store');
        });

        // Add to batch
        Route::prefix('/add-to-batch')->group(function () {
            Route::post('/invoice/{batch}', 'Api\BatchController@addinvoice');
            Route::post('/invoice-aiu/{batch}', 'Api\BatchController@addinvoiceaiu');
            Route::post('/invoice-mandate/{batch}', 'Api\BatchController@addinvoicemandate');
            Route::post('/invoice-export/{batch}', 'Api\BatchController@addinvoiceexport');
            Route::post('/invoice-contingency/{batch}', 'Api\BatchController@addinvoicecontingency');
            Route::post('/credit-note/{batch}', 'Api\BatchController@addcreditnote');
            Route::post('/debit-note/{batch}', 'Api\BatchController@adddebitnote');
        });

        // Send batch
        Route::post('send-batch/{batch}', 'Api\BatchController@sendbatch');

        // Status
        Route::prefix('/status')->group(function () {
            Route::post('/zip/{trackId}/{GuardarEn?}', 'Api\StateController@zip');
            Route::post('/document/{trackId}/{GuardarEn?}', 'Api\StateController@document');
            Route::post('/events-document/{trackId}', 'Api\StateController@events_document');
        });

        // Numbering Ranges
        Route::prefix('/numbering-range')->group(function () {
            Route::post('/', 'Api\NumberingRangeController@NumberingRange');
        });

        // Send email
        Route::prefix('/send-email')->group(function () {
            Route::post('/', 'Api\SendEmailController@SendEmail');
            Route::post('/external', 'Api\SendEmailController@sendExternalDocument');
        });

        // envio de correo nomina - pro 2
        Route::post('send-email-document-payroll', 'Api\SendEmailController@sendEmailDocumentPayroll');

        // Send email utilizado por el facturador pro 1

        Route::post('send_mail', 'EmailController@send');

        // Send event
        Route::prefix('/send-event')->group(function () {
            Route::post('/', 'Api\SendEventController@sendevent');
        });

        // Query events prefix and number
        Route::prefix('/query-events-prefix-number')->group(function () {
            Route::post('/{prefix}/{number}', 'Api\SendEventController@queryeventsprefixnumber');
        });

        // Send event
        Route::prefix('/send-event-data')->group(function () {
            Route::post('/', 'Api\SendEventController@sendeventdata');
        });

        // Query events UUID
        Route::prefix('/query-events-uuid')->group(function () {
            Route::post('/{uuid}', 'Api\SendEventController@queryeventsuuid');
        });

        // Query events UUID
        Route::prefix('/query-events-cufe-dian')->group(function () {
            Route::post('/{cufe}/{ambiente}', 'Api\SendEventController@queryeventscufedian');
        });

        // Payroll
        Route::prefix('/payroll')->group(function () {
            Route::post('/preeliminar-view', 'Api\PayrollController@preeliminarview');
            Route::post('/{testSetId}', 'Api\PayrollController@testSetStore');
            Route::post('/', 'Api\PayrollController@store');
            Route::get('/current_number/{type}/{ignore_state_document_id?}/{prefix?}', 'Api\PayrollController@currentNumber');
        });

        // Payroll Adjust Note
        Route::prefix('/payroll-adjust-note')->group(function () {
            Route::post('/{testSetId}', 'Api\PayrollAdjustNoteController@testSetStore');
            Route::post('/', 'Api\PayrollAdjustNoteController@store');
        });

        Route::get('download/{identification}/{file}/{type_response?}', 'Api\DownloadController@publicDownload');

    });
});

Route::get('invoice/xml/{filename}', function($fisicroute)
{
    $content = StorageService::getAuto($fisicroute);
    if ($content === null) {
        abort(404, 'XML no encontrado');
    }
    return response($content, 200, [
        'Content-Type' => 'application/xml'
    ]);
});

Route::get('invoice/pdf/{filename}', function($fisicroute)
{
    $content = StorageService::getAuto($fisicroute);
    if ($content === null) {
        abort(404, 'PDF no encontrado');
    }
    return response($content, 200, [
        'Content-Type' => 'application/pdf'
    ]);
});

Route::get('invoice/{identification}/{filename}', function($identification, $filename)
{
    $company = \App\Company::where('identification_number', $identification)->first();
    if ($company) StorageService::setCompany($company);
    $relativePath = "public/".$identification."/".$filename;
    return StorageService::downloadAuto($relativePath);
});

Route::get('receivedfile/{identification}/{filename}', function($identification, $filename)
{
    try{
        $company = \App\Company::where('identification_number', $identification)->first();
        if ($company) StorageService::setCompany($company);
        $relativePath = "public/".$identification."/".$filename;
        return StorageService::downloadAuto($relativePath);
    } catch (\Exception $e) {
        return [
            'success' => false,
            'message' => "No fue posible descargar el archivo {$filename} {$e->getMessage()}"
        ];
    }
});

Route::prefix('/information')->group(function () {
    Route::get('/{nit}', 'ResumeController@information');
    Route::get('/{nit}/{desde}', 'ResumeController@information');
    Route::get('/{nit}/{desde}/{hasta}', 'ResumeController@information');
    Route::get('/{nit}/page/{page}/page', 'ResumeController@information_by_page');
});

Route::prefix('/total_documents')->group(function () {
    Route::get('/{nit}', 'ResumeController@information_totals');
    Route::get('/{nit}/{desde}', 'ResumeController@information_totals');
    Route::get('/{nit}/{desde}/{hasta}', 'ResumeController@information_totals');
});

// Send email change customer password
Route::prefix('/change-customer-password')->group(function () {
    Route::post('/{customer_idnumber}/{show_view}', 'CustomerLoginController@RetrievePassword');
});

// Send email customer
Route::post('/send-email-customer', 'Api\SendEmailController@SendEmailCustomer')->name('send-email-customer');
Route::post('/send-email-customer/{ShowView}', 'Api\SendEmailController@SendEmailCustomer')->name('send-email-customer-view');

// Send email employee
Route::post('/send-email-employee', 'Api\SendEmailController@SendEmailEmployee')->name('send-email-employee');
Route::post('/send-email-employee/{ShowView}', 'Api\SendEmailController@SendEmailEmployee')->name('send-email-employee-view');

// Add customers/documents from xml
Route::post('/add-customers-documentos-xml/{nit}', 'Api\AddCostumersDocumentsXML@Organize')->name('add-customers-documentos-xml');

Route::post('/accept-reject-document', 'AcceptRejectDocumentController@ExecuteAcceptRejectDocument')->name('acceptrejectdocument');

Route::post('/download-file', 'AcceptRejectDocumentController@DownloadFile')->name('downloadfile');

if(env('ALLOW_PUBLIC_DOWNLOAD', TRUE)){
    Route::get('download/{identification}/{file}/{type_response?}',
        function($identification, $file, $type_response = FALSE)
        {
            $company = \App\Company::where('identification_number', $identification)->first();
            if ($company) StorageService::setCompany($company);
            $u = new \App\Utils;
            if(strpos($file, 'Attachment-') === false and strpos($file, 'ZipAttachm-') === false)
                if(StorageService::existsAuto("public/{$identification}/{$file}"))
                    if($type_response && $type_response === 'BASE64')
                        return [
                            'success' => true,
                            'message' => "Archivo: ".$file." se encontro.",
                            'filebase64'=>StorageService::getBase64AutoFallback("public/{$identification}/{$file}")
                        ];
                    elseif($type_response && $type_response === 'INLINE')
                        return StorageService::inlineAuto("public/{$identification}/{$file}");
                    else
                        return StorageService::downloadAuto("public/{$identification}/{$file}");
                else
                    return [
                        'success' => false,
                        'message' => "No se encontro el archivo: ".$file
                    ];
            else{
                if(strpos($file, 'ZipAttachm-') === false){
                    $filename = $u->attacheddocumentname($identification, $file);
                    if(StorageService::existsAuto("public/{$identification}/{$filename}.xml"))
                        if($type_response && $type_response === 'BASE64')
                            return [
                                'success' => true,
                                'message' => "Archivo: ".$filename.".xml se encontro.",
                                'filebase64'=>StorageService::getBase64AutoFallback("public/{$identification}/{$filename}.xml")
                            ];
                        elseif($type_response && $type_response === 'INLINE')
                            return StorageService::inlineAuto("public/{$identification}/{$filename}.xml");
                        else
                            return StorageService::downloadAuto("public/{$identification}/{$filename}.xml");
                    else
                        return [
                            'success' => false,
                            'message' => "No se encontro el archivo: ".$filename.".xml"
                        ];
                }
                else{
                    $filename = $u->attacheddocumentname($identification, $file);
                    if(StorageService::existsAuto("public/{$identification}/{$filename}.zip"))
                        if($type_response && $type_response === 'BASE64')
                            return [
                                'success' => true,
                                'message' => "Archivo: ".$filename.".zip se encontro.",
                                'filebase64'=>StorageService::getBase64AutoFallback("public/{$identification}/{$filename}.zip")
                            ];
                        elseif($type_response && $type_response === 'INLINE')
                            return StorageService::inlineAuto("public/{$identification}/{$filename}.zip");
                        else
                            return StorageService::downloadAuto("public/{$identification}/{$filename}.zip");
                    else
                        return [
                            'success' => false,
                            'message' => "No se encontro el archivo: ".$filename.".zip"
                        ];
                }
            }
        }
    );
}

// Ruta para visualizar archivos en el navegador (XML, PDF)
Route::get('view/{identification}/{file}', function($identification, $file) {
    $company = \App\Company::where('identification_number', $identification)->first();
    if ($company) StorageService::setCompany($company);
    $path = "public/{$identification}/{$file}";
    if (!StorageService::existsAuto($path)) {
        abort(404, 'Archivo no encontrado');
    }
    return StorageService::inlineAuto($path, $file);
});

Route::post('login', 'Api\AuthController@login');

// Mobile
Route::middleware(['auth:api', 'storage.context'])->group(function () {
    Route::get('documents', 'Api\MobileController@documents');

    // items
    Route::get('/items', 'Api\ItemController@records');
    Route::put('/items/{id}', 'Api\ItemController@update');
    Route::post('/items/add', 'Api\ItemController@store');

    //customers
    Route::get('/customers', 'Api\CustomerController@records');
    Route::post('/customers/add', 'Api\CustomerController@store');
    Route::put('/customers/{identification_number}', 'Api\CustomerController@update');

    //type currencies
    Route::get('/type-currencies', 'Api\TypeCurrencyController@records');

    //table
    Route::get('/table', 'Api\MobileController@table');
});
