<?php

namespace App\Http\Controllers\Api;

use App\User;
use App\Company;
use App\TypeDocument;
use App\Predecessor;
use App\Period;
use App\Worker;
use App\TypeWorker;
use App\PayrollPayment;
use App\Accrued;
use App\Deduction;
use App\PayrollPaymentDate;
use App\DocumentPayroll;
use Illuminate\Http\Request;
use App\Traits\DocumentTrait;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PayrollAdjustNoteRequest;
use ubl21dian\XAdES\SignPayrollNote;
use ubl21dian\XAdES\SignAttachedDocument;
use ubl21dian\Templates\SOAP\SendPayrollASync;
use ubl21dian\Templates\SOAP\SendPayrollSync;
use ubl21dian\Templates\SOAP\SendTestSetAsync;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceMail;
use Carbon\Carbon;
use DateTime;
use Storage;
use App\Services\StorageService;

class PayrollAdjustNoteController extends Controller
{
    use DocumentTrait;

    /**
     * Store.
     *
     * @param \App\Http\Requests\Api\PayrollAdjustNoteRequest $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(PayrollAdjustNoteRequest $request)
    {
        // User
        $user = auth()->user();
        // Configura SMTP y remitente (empresa/request -> .env -> usuario SMTP).
        $user->applyMailConfig($request->smtp_parameters);

        // User company
        $company = $user->company;

        // Verificar la disponibilidad de la DIAN antes de continuar
        $dian_url = $company->software->url;
        if (!$this->verificarEstadoDIAN($dian_url)) {
            // Manejar la indisponibilidad del servicio, por ejemplo:
            return [
                'success' => false,
                'message' => 'El servicio de la DIAN no está disponible en este momento. Por favor, inténtelo más tarde.',
            ];
        }

        // Verify Certificate
        $certificate_days_left = 0;
        $c = $this->verify_certificate();
        if(!$c['success'])
            return $c;
        else
            $certificate_days_left = $c['certificate_days_left'];

        if($company->type_plan2->state == false)
            return [
                'success' => false,
                'message' => 'El plan en el que esta registrado la empresa se encuentra en el momento INACTIVO para enviar documentos electronicos...',
            ];

        if($company->state == false)
            return [
                'success' => false,
                'message' => 'La empresa se encuentra en el momento INACTIVA para enviar documentos electronicos...',
            ];

        if($company->type_plan2->period != 0 && $company->absolut_plan_documents == 0){
            $firstDate = new DateTime($company->start_plan_date2);
            $secondDate = new DateTime(Carbon::now()->format('Y-m-d H:i'));
            $intvl = $firstDate->diff($secondDate);
            switch($company->type_plan2->period){
                case 1:
                    if($intvl->y >= 1 || $intvl->m >= 1 || $this->qty_docs_period("PAYROLL") >= $company->type_plan2->qty_docs_payroll)
                        return [
                            'success' => false,
                            'message' => 'La empresa ha llegado al limite de tiempo/documentos del plan por mensualidad, por favor renueve su membresia...',
                        ];
                case 2:
                    if($intvl->y >= 1 || $this->qty_docs_period("PAYROLL") >= $company->type_plan2->qty_docs_payroll)
                        return [
                            'success' => false,
                            'message' => 'La empresa ha llegado al limite de tiempo/documentos del plan por anualidad, por favor renueve su membresia...',
                        ];
                case 3:
                    if($this->qty_docs_period("PAYROLL") >= $company->type_plan2->qty_docs_payroll)
                        return [
                            'success' => false,
                            'message' => 'La empresa ha llegado al limite de documentos del plan por paquetes, por favor renueve su membresia...',
                        ];
            }
        }
        else{
            if($company->absolut_plan_documents != 0){
                if($this->qty_docs_period("ABSOLUT") >= $company->absolut_plan_documents)
                    return [
                        'success' => false,
                        'message' => 'La empresa ha llegado al limite de documentos del plan mixto, por favor renueve su membresia...',
                    ];
            }
        }

        // Type document
        $typeDocument = TypeDocument::findOrFail($request->type_document_id);

        // Type note
        $type_note = $request->type_note;

        // Predecessor
        if($request->predecessor)
            $predecessor = new Predecessor($request->predecessor);
        else
            $predecessor = NULL;

        // Period
        if($request->period)
            $period = new Period($request->period);
        else
            $period = NULL;

        // Worker
        if($request->worker) {
            $worker = new Worker($request->worker);
            $worker->initRelations();
        } else {
            $worker = NULL;
        }

        // Payment
        if($request->payment) {
            $payment = new PayrollPayment($request->payment);
            $payment->initRelations();
        } else {
            $payment = NULL;
        }

        // Payment Dates
        $payment_dates = collect();
        foreach ($request->payment_dates ?? [] as $payment_date) {
            $payment_dates->push(new PayrollPaymentDate($payment_date));
        }

        // Resolution
        $request->resolution->number = $request->consecutive;
        $resolution = $request->resolution;

        if(env('VALIDATE_BEFORE_SENDING', false)){
            $doc = DocumentPayroll::where('type_document_id', $request->type_document_id)->where('identification_number', $company->identification_number)->where('prefix', $resolution->prefix)->where('consecutive', $request->consecutive)->where('state_document_id', 1)->get();
            if(count($doc) > 0)
                return [
                    'success' => false,
                    'message' => 'Este documento ya fue enviado anteriormente, se registra en la base de datos.',
                    'employee' => $doc[0]->employee_id,
                    'cune' => $doc[0]->cune,
                    'total_payroll' => $doc[0]->total_payroll,
                ];
        }

        // Notes
        $notes = $request->notes;

        // Accrueds
        if($request->accrued)
            $accrued = new Accrued($request->accrued);
        else
            $accrued = NULL;

        // Deductions
        if($request->deductions) {
            $deductions = new Deduction($request->deductions);
            $deductions->initRelations();
        } else {
            $deductions = NULL;
        }

        // Splited Name
        $splited_name = $this->split_name($user->name);

        // Document Payroll
        $payroll_note_doc = new DocumentPayroll();
        $payroll_note_doc->identification_number = $company->identification_number;
        $payroll_note_doc->state_document_id = 0;
        $payroll_note_doc->type_document_id = $request->type_document_id;
        $payroll_note_doc->consecutive = $request->consecutive;
        if(isset($worker))
            $payroll_note_doc->employee_id = $worker->identification_number;
        else
            $payroll_note_doc->employee_id = 0;
        $payroll_note_doc->date_issue = date("Y-m-d H:i:s");
        if(isset($accrued))
            $payroll_note_doc->accrued_total = $accrued->accrued_total;
        else
            $payroll_note_doc->accrued_total = 0;
        if(isset($deductions))
            $payroll_note_doc->deductions_total = $deductions->deductions_total;
        else
            $payroll_note_doc->deductions_total = 0;
        if(isset($deductions) && isset($accrued))
            $payroll_note_doc->total_payroll =  $accrued->accrued_total - $deductions->deductions_total;
        else
            $payroll_note_doc->total_payroll =  0;
        $payroll_note_doc->request_api = json_encode($request->all());
        $payroll_note_doc->prefix = $resolution->prefix;
        $payroll_note_doc->xml = "NAS-{$resolution->next_consecutive}.xml";
        $payroll_note_doc->pdf = "NAS-{$resolution->next_consecutive}.pdf";
        $payroll_note_doc->save();

        // Create XML
        $payroll_note = $this->createXML(compact('user', 'company', 'predecessor', 'period', 'worker', 'resolution', 'payment', 'payment_dates', 'typeDocument', 'notes', 'accrued', 'deductions', 'request', 'type_note', 'splited_name'));

        // Signature XML
        $signPayrollNote = new SignPayrollNote($company->certificate->path, $company->certificate->password);
        $signPayrollNote->softwareID = $company->software->identifier_payroll;
        $signPayrollNote->pin = $company->software->pin_payroll;
        $signPayrollNote->technicalKey = $resolution->technical_key;

        StorageService::ensureDirectory("public/{$company->identification_number}");

        $signPayrollNote->GuardarEn = StorageService::tempPath("public/{$company->identification_number}/NA-{$resolution->next_consecutive}.xml");

        $sendPayrollSync = new SendPayrollSync($company->certificate->path, $company->certificate->password);
        $sendPayrollSync->To = $company->software->url_payroll;
        $sendPayrollSync->fileName = "{$resolution->next_consecutive}.xml";
        $sendPayrollSync->contentFile = $this->zipBase64($company, $resolution, $signPayrollNote->sign($payroll_note), StorageService::tempPath("public/{$company->identification_number}/NAS-{$resolution->next_consecutive}"));

        $QRStr = $this->createPDFPayroll($user, $company, $predecessor, $period, $worker, $resolution, $payment, $payment_dates, $typeDocument, $notes, $accrued, $deductions, $request, $signPayrollNote->ConsultarCUNE(), "PAYROLLADJUSTNOTE");

        $filename = '';
        $respuestadian = '';
        $typeDocument = TypeDocument::findOrFail(7);
        $ar = new \DOMDocument;
        try{
            $respuestadian = $sendPayrollSync->signToSend(StorageService::tempPath("public/{$company->identification_number}/ReqNA-{$resolution->next_consecutive}.xml"))->getResponseToObject(StorageService::tempPath("public/{$company->identification_number}/RptaNA-{$resolution->next_consecutive}.xml"));
            if(isset($respuestadian->html))
                return [
                    'success' => false,
                    'message' => "El servicio DIAN no se encuentra disponible en el momento, reintente mas tarde..."
                ];

            if($respuestadian->Envelope->Body->SendNominaSyncResponse->SendNominaSyncResult->IsValid == 'true'){
                $filename = str_replace('ni', 'ad', str_replace('nd', 'ad', str_replace('nc', 'ad', str_replace('fv', 'ad', $respuestadian->Envelope->Body->SendNominaSyncResponse->SendNominaSyncResult->XmlFileName))));
                if($request->atacheddocument_name_prefix)
                    $filename = $request->atacheddocument_name_prefix.$filename;
                $cufecude = $respuestadian->Envelope->Body->SendNominaSyncResponse->SendNominaSyncResult->XmlDocumentKey;
                $payroll_note_doc->state_document_id = 1;
                $payroll_note_doc->cune = $cufecude;
                $payroll_note_doc->save();
                $signedxml = StorageService::getAutoLocal("xml/{$company->id}/".$respuestadian->Envelope->Body->SendNominaSyncResponse->SendNominaSyncResult->XmlFileName.".xml");
                if(strpos($signedxml, "</Invoice>") > 0)
                    $td = '/Invoice';
                else
                    if(strpos($signedxml, "</CreditNote>") > 0)
                        $td = '/CreditNote';
                    else
                        if(strpos($signedxml, "</DebitNote>") > 0)
                            $td = '/DebitNote';
                        else
                            $td = '/NominaIndividual';
                $appresponsexml = base64_decode($respuestadian->Envelope->Body->SendNominaSyncResponse->SendNominaSyncResult->XmlBase64Bytes);
                $ar->loadXML($appresponsexml);
                $fechavalidacion = $ar->documentElement->getElementsByTagName('IssueDate')->item(0)->nodeValue;
                $horavalidacion = $ar->documentElement->getElementsByTagName('IssueTime')->item(0)->nodeValue;
                $at = '';
                if(isset($request->sendmail) && (!$this->getTag($signedxml, 'EliminandoPredecesor', 0, 'CUNEPred'))){
                    if($request->sendmail && $worker){
                        $payroll = DocumentPayroll::where('identification_number', '=', $company->identification_number)
                                                    ->where('employee_id', '=', $worker->identification_number)
                                                    ->where('prefix', '=', $this->getTag($signedxml, 'NumeroSecuenciaXML', 0, 'Prefijo'))
                                                    ->where('consecutive', '=', $this->getTag($signedxml, 'NumeroSecuenciaXML', 0, 'Consecutivo'))
                                                    ->where('state_document_id', '=', 1)->get();
                        if(count($payroll) > 0){
                            try{
                                Mail::to($worker->email)->send(new PayrollMail($payroll, $worker, $company, FALSE, $filename, $request));
                                if(isset($request->sendmailtome) && $request->sendmailtome == true)
                                    Mail::to($user->email)->send(new PayrollMail($payroll, $worker, $company, FALSE, $filename, $request));
                            } catch (\Exception $m) {
                                \Log::debug($m->getMessage());
                            }
                        }
                    }
                }
            }
            else
                $at = '';
        } catch (\Exception $e) {
            return $e->getMessage().' '.preg_replace("/[\r\n|\n|\r]+/", "", json_encode($respuestadian));
        }
        return [
            'message' => "{$typeDocument->name} #{$resolution->next_consecutive} generada con éxito",
            'ResponseDian' => $respuestadian,
            'payrollxml'=>base64_encode(file_get_contents(StorageService::tempPath("public/{$company->identification_number}/NAS-{$resolution->next_consecutive}.xml"))),
            'zippayrollxml'=>base64_encode(file_get_contents(StorageService::tempPath("public/{$company->identification_number}/NAS-{$resolution->next_consecutive}.zip"))),
            'unsignedpayrollxml'=>base64_encode(file_get_contents(StorageService::tempPath("public/{$company->identification_number}/NA-{$resolution->next_consecutive}.xml"))),
            'reqni'=>base64_encode(file_get_contents(StorageService::tempPath("public/{$company->identification_number}/ReqNA-{$resolution->next_consecutive}.xml"))),
            'rptani'=>base64_encode(file_get_contents(StorageService::tempPath("public/{$company->identification_number}/RptaNA-{$resolution->next_consecutive}.xml"))),
            'attacheddocument'=>base64_encode($at),
            'urlpayrollxml'=>"NAS-{$resolution->next_consecutive}.xml",
            'urlpayrollpdf'=>"NAS-{$resolution->next_consecutive}.pdf",
            'urlpayrollattached'=>"{$filename}.xml",
            'cune' => $signPayrollNote->ConsultarCUNE(),
//            'QRStr' => $QRStr
            'certificate_days_left' => $certificate_days_left,
        ];
    }

    /**
     * Test set store.
     *
     * @param \App\Http\Requests\Api\PayrollAdjustNoteRequest $request
     * @param string                                $testSetId
     *
     * @return \Illuminate\Http\Response
     */
    public function testSetStore(PayrollAdjustNoteRequest $request, $testSetId)
    {
        // User
        $user = auth()->user();

        // User company
        $company = $user->company;

        // Verificar la disponibilidad de la DIAN antes de continuar
        $dian_url = $company->software->url;
        if (!$this->verificarEstadoDIAN($dian_url)) {
            // Manejar la indisponibilidad del servicio, por ejemplo:
            return [
                'success' => false,
                'message' => 'El servicio de la DIAN no está disponible en este momento. Por favor, inténtelo más tarde.',
            ];
        }

        // Verify Certificate
        $certificate_days_left = 0;
        $c = $this->verify_certificate();
        if(!$c['success'])
            return $c;
        else
            $certificate_days_left = $c['certificate_days_left'];

        // Type document
        $typeDocument = TypeDocument::findOrFail($request->type_document_id);

        // Type note
        $type_note = $request->type_note;

        // Predecessor
        if($request->predecessor)
            $predecessor = new Predecessor($request->predecessor);
        else
            $predecessor = NULL;

        // Period
        if($request->period)
            $period = new Period($request->period);
        else
            $period = NULL;

        // Worker
        if($request->worker) {
            $worker = new Worker($request->worker);
            $worker->initRelations();
        } else {
            $worker = NULL;
        }

        // Payment
        if($request->payment) {
            $payment = new PayrollPayment($request->payment);
            $payment->initRelations();
        } else {
            $payment = NULL;
        }

        // Payment Dates
        $payment_dates = collect();
        foreach ($request->payment_dates ?? [] as $payment_date) {
            $payment_dates->push(new PayrollPaymentDate($payment_date));
        }

        // Resolution
        $request->resolution->number = $request->consecutive;
        $resolution = $request->resolution;

        if(env('VALIDATE_BEFORE_SENDING', false)){
            $doc = DocumentPayroll::where('type_document_id', $request->type_document_id)->where('identification_number', $company->identification_number)->where('prefix', $resolution->prefix)->where('consecutive', $request->consecutive)->where('state_document_id', 1)->get();
            if(count($doc) > 0)
                return [
                    'success' => false,
                    'message' => 'Este documento ya fue enviado anteriormente, se registra en la base de datos.',
                    'employee' => $doc[0]->employee_id,
                    'cune' => $doc[0]->cune,
                    'total_payroll' => $doc[0]->total_payroll,
                ];
        }

        // Notes
        $notes = $request->notes;

        // Accrueds
        if($request->accrued)
            $accrued = new Accrued($request->accrued);
        else
            $accrued = NULL;

        // Deductions
        if($request->deductions) {
            $deductions = new Deduction($request->deductions);
            $deductions->initRelations();
        } else {
            $deductions = NULL;
        }

        // Splited Name
        $splited_name = $this->split_name($user->name);

        // Document Payroll
        $payroll_note_doc = new DocumentPayroll();
        $payroll_note_doc->identification_number = $company->identification_number;
        $payroll_note_doc->state_document_id = 0;
        $payroll_note_doc->type_document_id = $request->type_document_id;
        $payroll_note_doc->consecutive = $request->consecutive;
        if(isset($worker))
            $payroll_note_doc->employee_id = $worker->identification_number;
        else
            $payroll_note_doc->employee_id = 0;
        $payroll_note_doc->date_issue = date("Y-m-d H:i:s");
        if(isset($accrued))
            $payroll_note_doc->accrued_total = $accrued->accrued_total;
        else
            $payroll_note_doc->accrued_total = 0;
        if(isset($deductions))
            $payroll_note_doc->deductions_total = $deductions->deductions_total;
        else
            $payroll_note_doc->deductions_total = 0;
        if(isset($deductions) && isset($accrued))
            $payroll_note_doc->total_payroll =  $accrued->accrued_total - $deductions->deductions_total;
        else
            $payroll_note_doc->total_payroll =  0;
        $payroll_note_doc->request_api = json_encode($request->all());
        $payroll_note_doc->prefix = $resolution->prefix;
        $payroll_note_doc->xml = "NAS-{$resolution->next_consecutive}.xml";
        $payroll_note_doc->pdf = "NAS-{$resolution->next_consecutive}.pdf";
        $payroll_note_doc->save();

        // Create XML
        $payroll_note = $this->createXML(compact('user', 'company', 'predecessor', 'period', 'worker', 'resolution', 'payment', 'payment_dates', 'typeDocument', 'notes', 'accrued', 'deductions', 'request', 'type_note', 'splited_name'));
//        return $payroll_note->saveXML();

        // Signature XML
        $signPayrollNote = new SignPayrollNote($company->certificate->path, $company->certificate->password);
        $signPayrollNote->softwareID = $company->software->identifier_payroll;
        $signPayrollNote->pin = $company->software->pin_payroll;
        $signPayrollNote->technicalKey = $resolution->technical_key;

        StorageService::ensureDirectory("public/{$company->identification_number}");

        $signPayrollNote->GuardarEn = StorageService::tempPath("public/{$company->identification_number}/NA-{$resolution->next_consecutive}.xml");

        $sendPayrollASync = new SendPayrollASync($company->certificate->path, $company->certificate->password);
        $sendPayrollASync->To = $company->software->url_payroll;
        $sendPayrollASync->fileName = "{$resolution->next_consecutive}.xml";
        $sendPayrollASync->contentFile = $this->zipBase64($company, $resolution, $signPayrollNote->sign($payroll_note), StorageService::tempPath("public/{$company->identification_number}/NAS-{$resolution->next_consecutive}"));
        $sendPayrollASync->testSetId = $testSetId;
//        return $payroll_note->saveXML();

        $QRStr = $this->createPDFPayroll($user, $company, $predecessor, $period, $worker, $resolution, $payment, $payment_dates, $typeDocument, $notes, $accrued, $deductions, $request, $signPayrollNote->ConsultarCUNE(), "PAYROLLADJUSTNOTE");

        return [
            'message' => "{$typeDocument->name} #{$resolution->next_consecutive} generada con éxito",
            'ResponseDian' => $sendPayrollASync->signToSend(StorageService::tempPath("public/{$company->identification_number}/ReqNA-{$resolution->next_consecutive}.xml"))->getResponseToObject(StorageService::tempPath("public/{$company->identification_number}/RptaNA-{$resolution->next_consecutive}.xml")),
            'payrollxml'=>base64_encode(file_get_contents(StorageService::tempPath("public/{$company->identification_number}/NAS-{$resolution->next_consecutive}.xml"))),
            'zippayrollxml'=>base64_encode(file_get_contents(StorageService::tempPath("public/{$company->identification_number}/NAS-{$resolution->next_consecutive}.zip"))),
            'unsignedpayrollxml'=>base64_encode(file_get_contents(StorageService::tempPath("public/{$company->identification_number}/NA-{$resolution->next_consecutive}.xml"))),
            'reqni'=>base64_encode(file_get_contents(StorageService::tempPath("public/{$company->identification_number}/ReqNA-{$resolution->next_consecutive}.xml"))),
            'rptani'=>base64_encode(file_get_contents(StorageService::tempPath("public/{$company->identification_number}/RptaNA-{$resolution->next_consecutive}.xml"))),
            'urlpayrollxml'=>"NAS-{$resolution->next_consecutive}.xml",
            'urlpayrollpdf'=>"NAS-{$resolution->next_consecutive}.pdf",
            'cune' => $signPayrollNote->ConsultarCUNE(),
//            'QRStr' => $QRStr
            'certificate_days_left' => $certificate_days_left,
        ];
    }
}
