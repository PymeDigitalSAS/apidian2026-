<?php

use Illuminate\Support\Facades\Route;
use App\Services\StorageService;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
/**/
// Rutas de autenticación manuales (reemplazo de Auth::routes())
// Login Routes
Route::get('login', 'Auth\LoginController@showLoginForm')->name('login');
Route::post('login', 'Auth\LoginController@login');
Route::post('logout', 'Auth\LoginController@logout')->name('logout');

// Registro y reseteo de contraseña deshabilitados según configuración original
// Route::get('register', 'Auth\RegisterController@showRegistrationForm')->name('register');
// Route::post('register', 'Auth\RegisterController@register');
// Route::get('password/reset', 'Auth\ForgotPasswordController@showLinkRequestForm')->name('password.request');
// Route::post('password/email', 'Auth\ForgotPasswordController@sendResetLinkEmail')->name('password.email');
// Route::get('password/reset/{token}', 'Auth\ResetPasswordController@showResetForm')->name('password.reset');
// Route::post('password/reset', 'Auth\ResetPasswordController@reset')->name('password.update');

// rutas listado de documentos
Route::get('/ownerapilogin', 'OwnerApiLoginController@ShowOwnerLoginForm');
Route::get('/okownerlogin', 'OwnerApiLoginController@PasswordOwnerVerify')->name('homeowner');
Route::get('/okownersearch', 'OwnerApiLoginController@OwnerSearch')->name('ownersearch');
Route::get('/okownersearchpayrolls', 'OwnerApiLoginController@OwnerSearchPayrolls')->name('ownersearchpayrolls');
Route::get('/okownerpayrolls', 'OwnerApiLoginController@OwnerPayrolls')->name('ownerpayrolls');
Route::get('/owner-password', 'OwnerApiLoginController@OwnerPassword')->name('owner-password');
Route::post('/reset-owner-password', 'OwnerApiLoginController@ResetOwnerPassword')->name('resetownerpassword');

// acciones para empleados de nomina
Route::get('/employeelogin/{company_idnumber}/{employee_idnumber}', 'EmployeeLoginController@ShowEmployeeLoginForm');
Route::post('/okemployeelogin/{company_idnumber}/{employee_idnumber}', 'EmployeeLoginController@PasswordEmployeeVerify')->name('homeemployees');
Route::get('/employee-password/{company_idnumber}/{employee_idnumber}', 'EmployeeLoginController@EmployeePassword')->name('employee-password');
Route::post('/reset-employee-password/{company_idnumber}/{employee_idnumber}', 'EmployeeLoginController@ResetEmployeePassword')->name('resetemployeepassword');
Route::get('/retrieve-password-employee/{employee_idnumber}', 'EmployeeLoginController@RetrievePasswordEmployee')->name('retrievepasswordemployee');
Route::get('/accept-retrieve-password-employee/{employee_idnumber}/{hash}', 'EmployeeLoginController@AcceptRetrievePasswordEmployee')->name('acceptretrievepasswordemployee');

Route::get('/sellerlogin/{company_idnumber}', 'SellerLoginController@ShowSellerLoginForm');
Route::get('/oksellerlogin/{company_idnumber}', 'SellerLoginController@PasswordSellerVerify')->name('homesellers');
Route::get('/oksellerssearch/{company_idnumber}', 'SellerLoginController@SellersSearch')->name('sellerssearch');
Route::get('/oksellerspayrolls/{company_idnumber}', 'SellerLoginController@SellersPayrolls')->name('sellerspayrolls');
Route::get('/oksellerspayrollssearch/{company_idnumber}', 'SellerLoginController@SellersPayrollsSearch')->name('sellerspayrollssearch');
Route::post('/oksellersdocumentsreception/{company_idnumber}', 'SellerLoginController@SellersDocumentsReceptionView')->name('ok-sellers-documents-reception-view');
Route::post('/sellers-document-reception/{company_idnumber}', 'SellerLoginController@SellersDocumentsReception')->name('sellers-documents-reception');
Route::get('/oksellersradianevents/{company_idnumber}', 'SellerLoginController@SellersRadianEventsView')->name('ok-sellers-radian-events-view');
Route::get('/oksellersradiansearch/{company_idnumber}', 'SellerLoginController@SellersRadianSearch')->name('sellersradiansearch');
Route::get('/seller-password/{company_idnumber}', 'SellerLoginController@SellerPassword')->name('seller-password');
Route::post('/reset-seller-password/{company_idnumber}', 'SellerLoginController@ResetSellerPassword')->name('resetsellerpassword');
Route::get('/retrieve-password-seller/{company_idnumber}', 'SellerLoginController@RetrievePasswordSeller')->name('retrievepasswordseller');
Route::get('/accept-retrieve-password-seller/{company_idnumber}/{hash}', 'SellerLoginController@AcceptRetrievePasswordSeller')->name('acceptretrievepasswordseller');

Route::get('/customerlogin/{company_idnumber}/{customer_idnumber}', 'CustomerLoginController@ShowLoginForm');
Route::post('/okcustomerlogin/{company_idnumber}/{customer_idnumber}', 'CustomerLoginController@PasswordVerify')->name('homecustomers');
Route::get('/customer-password/{company_idnumber}/{customer_idnumber}', 'CustomerLoginController@CustomerPassword')->name('customer-password');
Route::post('/reset-customer-password/{company_idnumber}/{customer_idnumber}', 'CustomerLoginController@ResetCustomerPassword')->name('resetcustomerpassword');
Route::get('/retrieve-password/{customer_idnumber}', 'CustomerLoginController@RetrievePassword')->name('retrievepassword');
Route::get('/accept-retrieve-password/{customer_idnumber}/{hash}', 'CustomerLoginController@AcceptRetrievePassword')->name('acceptretrievepassword');

Route::get('/accept-reject-document/{company_idnumber}/{customer_idnumber}/{prefix}/{docnumber}/{issuedate}', 'AcceptRejectDocumentController@ShowViewAcceptRejectDocument');

Route::get('/listings', 'ListingController@index');
Route::get('/portal', 'ListingController@index');
Route::get('/', 'HomeController@index')->name('home');

Route::group(['middleware' => ['auth', 'company.web.access']], function() {
    Route::get('/home', 'HomeController@index')->name('home');
    Route::get('/tools', 'HomeController@tools')->name('tools');

    // Monitoreo de recursos del servidor (solo admin de plataforma)
    Route::get('/monitoring', 'MonitoringController@index')->name('monitoring.index');
    Route::get('/monitoring/live', 'MonitoringController@live')->name('monitoring.live');
    Route::get('/monitoring/history', 'MonitoringController@history')->name('monitoring.history');
    Route::get('/monitoring/peaks', 'MonitoringController@peaks')->name('monitoring.peaks');
    Route::get('/monitoring/companies', 'MonitoringController@companies')->name('monitoring.companies');

    // Copias de seguridad / Backups (solo admin de plataforma)
    Route::get('/backups', 'BackupController@index')->name('backups.index');
    Route::get('/backups/list', 'BackupController@list')->name('backups.list');
    Route::get('/backups/purges', 'BackupController@purges')->name('backups.purges');
    Route::get('/backups/company/{company}/preview', 'BackupController@preview')->name('backups.preview');
    Route::post('/backups/recalculate', 'BackupController@recalculate')->name('backups.recalculate');
    Route::post('/backups', 'BackupController@store')->name('backups.store');
    Route::get('/backups/{backup}/download', 'BackupController@download')->name('backups.download');
    Route::delete('/backups/{backup}', 'BackupController@destroy')->name('backups.destroy');
    Route::post('/backups/free-space', 'BackupController@freeSpace')->name('backups.free');

    // Extractor de facturas DIAN (servicio Node externo)
    Route::get('/extractor', 'ExtractorController@index')->name('extractor.index');
    Route::post('/extractor/test', 'ExtractorController@testConnection')->name('extractor.test');
    Route::post('/extractor/extract', 'ExtractorController@extract')->name('extractor.extract');
    Route::post('/extractor/cufes', 'ExtractorController@cufes')->name('extractor.cufes');
    Route::post('/extractor/extract-cert', 'ExtractorController@extractWithCertificate')->name('extractor.extract-cert');
    Route::get('/extractor/job/{id}', 'ExtractorController@jobStatus')->name('extractor.job');
    Route::get('/extractor/download/{path}', 'ExtractorController@download')->where('path', '.*')->name('extractor.download');

    Route::get('/company/{company}', 'HomeController@company')->name('company');
    // Eliminar empresa y todos sus datos (solo admin de plataforma). Irreversible.
    Route::post('/company/{company}/delete', 'HomeController@destroyCompany')->name('company.destroy');
    Route::get('/company/{company}/document/{cufe}', 'HomeController@getXml')->name('getXml');
    Route::get('/company/{company}/events', 'HomeController@events')->name('company.events');
    Route::get('/company/{company}/payrolls', 'HomeController@payrolls')->name('company.payrolls');
    Route::get('/documents', 'HomeController@listDocuments')->name('listdocuments');
    Route::get('/taxes', 'HomeController@listTaxes')->name('listtaxes');
    Route::get('/listconfigurations', 'HomeController@listConfigurations')->name('listconfigurations');
    Route::put('/companies/{company}', 'HomeController@update')->name('companies.update');
    Route::patch('/companies/{company}/storage-mode', 'HomeController@updateStorageMode')->name('companies.storage-mode');

    // users for rips
    Route::get('companies/{company}/users', 'CompanyUserController@index')->name('company.users.index');
    Route::post('companies/{company}/users', 'CompanyUserController@store')->name('company.users.store');
    Route::put('companies/{company}/users/{user}', 'CompanyUserController@update')->name('company.users.update');

    // emails
    Route::get('companies/{company}/configuration/email', 'CompanyUserController@emailIndex')->name('company.email.index');
    Route::post('companies/{company}/configuration/email', 'CompanyUserController@emailStore')->name('company.email.store');

    // resoluciones
    Route::get('companies/{company}/configuration/resolutions', 'ResolutionController@index')->name('company.resolutions.index');
    Route::post('companies/{company}/configuration/resolutions', 'ResolutionController@store')->name('company.resolutions.store');
    Route::put('companies/{company}/configuration/resolutions/{resolutionId}', 'ResolutionController@update')->name('company.resolutions.edit');
    Route::patch('companies/{company}/configuration/resolutions/{resolutionId}', 'ResolutionController@updateEnvironment')->name('company.resolutions.update');

    // production
    Route::get('companies/{company}/production', 'ProductionController@index')->name('company.production.index');
    Route::post('companies/{company}/production', 'ProductionController@process')->name('company.production.process');
    Route::post('/company/{company}/production/consult-resolutions/{type}', 'ProductionController@consultarResoluciones')->name('company.production.consult-resolutions');
    Route::get('companies/{company}/configuration/resolutions/create', 'ResolutionController@create')->name('company.resolutions.create');
    Route::get('/companies/{company}/production/invoice', 'ProductionController@productionInvoice')->name('company.production.invoice.index');
    Route::get('/companies/{company}/production/pos', 'ProductionController@productionPos')->name('company.production.pos.index');
    Route::get('/companies/{company}/production/support', 'ProductionController@productionSupport')->name('company.production.support.index');
    Route::get('/companies/{company}/production/payroll', 'ProductionController@productionPayroll')->name('company.production.payroll.index');
    Route::get('/companies/{company}/production/event', 'ProductionController@productionEvent')->name('company.production.event.index');
    Route::put('/companies/{company}/production/environment/{type}', 'ProductionController@updateEnvironment')->name('company.production.environment.update');
    Route::put('/companies/{company}/production/software/{type?}', 'ProductionController@storeSoftware')->where('type', 'invoice|payroll|pos|support')->defaults('type', 'invoice')->name('company.production.software.store');    //configuration
    Route::put('/companies/{company}/production/template/{type}', 'ProductionController@updateTemplate')->where('type', 'invoice|eqdocs|support-document|payroll')->name('company.production.template.update');
    Route::get('/companies/{company}/production/tabs/{type?}', 'ProductionController@documentsTabs')->name('company.production.tabs');

    Route::get('/configuration', 'ConfigurationController@index')->name('configuration_index');
    Route::get('/configuration_admin', 'ConfigurationController@configuration_admin')->name('configuration_admin');
    Route::post('/configuration', 'ConfigurationController@store')->name('configuration_store');
    Route::get('configuration/tables', 'ConfigurationController@tables');
    Route::post('configuration/extract-rut', 'ConfigurationController@extractRut'); // usa RutExtractorV2 (parser por casillas del formulario DIAN 001)
    Route::get('configuration/records', 'ConfigurationController@records');

    Route::get('tax', 'TaxController@index')->name('tax_index');
    Route::get('tax/records', 'TaxController@records');

    Route::get('documents', 'DocumentController@index')->name('documents_index');
    Route::get('documents/records', 'DocumentController@records');
    Route::get('documents/downloadxml/{xml}', 'DocumentController@downloadxml');
    Route::get('documents/downloadpdf/{pdf}', 'DocumentController@downloadpdf');
    Route::post('document/change-state', 'DocumentController@changeState')->name('document.change-state');

    // documentacion
    Route::get('documentation', '\L5Swagger\Http\Controllers\SwaggerController@api')->name('documentation');

    // logs
    Route::get('logs', [\Rap2hpoutre\LaravelLogViewer\LogViewerController::class, 'index'])->name('logs');

});

Route::get('qr', 'QrController@generateQr');
Route::get('pdf','ListingController@generatePDF');
Route::get('/listings', 'ListingController@index');

Route::get('invoice/xml/{filename}', function($fisicroute)
{
    $content = StorageService::getAuto($fisicroute);
    if ($content === null) {
        abort(404, 'Archivo XML no encontrado');
    }
    return response($content, 200, [
        'Content-Type' => 'application/xml'
    ]);
});

Route::get('invoice/pdf/{filename}', function($fisicroute)
{
    $content = StorageService::getAuto("facturas/pdf/".$fisicroute);
    if ($content === null) {
        abort(404, 'Archivo PDF no encontrado');
    }
    return response($content, 200, [
        'Content-Type' => 'application/pdf'
    ]);
});

//mail test
Route::get('laravel-send-email', 'EmailController@sendEMail');
















