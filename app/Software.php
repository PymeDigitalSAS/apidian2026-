<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Software extends Model
{
    /**
     * Endpoints de la DIAN. Solo existen estos dos ambientes.
     */
    const DIAN_URL_PRODUCTION = 'https://vpfe.dian.gov.co/WcfDianCustomerServices.svc';
    const DIAN_URL_HABILITACION = 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc';

    /**
     * Columna de ambiente de la empresa que manda sobre cada URL.
     */
    const ENVIRONMENT_COLUMNS = [
        'url' => 'type_environment_id',
        'url_payroll' => 'payroll_type_environment_id',
        'url_eqdocs' => 'eqdocs_type_environment_id',
        'url_support_document' => 'support_document_type_environment_id',
        'url_event' => 'event_type_environment_id',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'identifier', 'pin', 'url', 'identifier_payroll', 'pin_payroll', 'url_payroll', 'identifier_eqdocs', 'pin_eqdocs', 'url_eqdocs', 'identifier_support_document', 'pin_support_document', 'url_support_document', 'url_event',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'company_id',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * El ambiente vive en `companies.*_type_environment_id`: es lo que se escribe en el
     * ProfileExecutionID y el UUID/@schemeID del XML. La URL es el destino del envio.
     * Guardarlos por separado los desincronizaba y la DIAN rechazaba con FAD04/FAD07
     * ("no indica un valor valido para ambiente de destino del documento").
     *
     * Por eso la URL no se lee de la columna: se deriva siempre del ambiente. Asi el
     * documento no puede salir hacia un ambiente distinto al que declara, sin importar
     * que haya quedado guardado en la fila ni que escritor la haya tocado.
     *
     * Una URL que no sea ninguno de los dos endpoints de la DIAN (un proxy propio, un
     * mock de pruebas) se respeta tal cual: ahi el ambiente no aplica.
     */
    private function dianUrlFor($stored, $urlColumn)
    {
        if (!empty($stored) && strpos($stored, 'vpfe') === false) {
            return $stored;
        }

        $company = $this->company;

        if (is_null($company)) {
            // Software recien instanciado y todavia sin empresa asociada.
            return empty($stored) ? self::DIAN_URL_HABILITACION : $stored;
        }

        $environmentColumn = self::ENVIRONMENT_COLUMNS[$urlColumn];

        return ((int) ($company->{$environmentColumn} ?: 2)) === 1
            ? self::DIAN_URL_PRODUCTION
            : self::DIAN_URL_HABILITACION;
    }

    /**
     * URL que corresponde a un type_document_id de la DIAN. Mismo criterio que
     * ConfigurationController::getResolutionEnvironmentId(), para que la consulta
     * salga contra el mismo ambiente en que se emitio el documento.
     */
    public function urlForTypeDocument($type_document_id)
    {
        $id = (int) $type_document_id;

        if (in_array($id, [8, 14], true))
            return $this->url_event;

        if (in_array($id, [9, 10], true))
            return $this->url_payroll;

        if (in_array($id, [11, 13], true))
            return $this->url_support_document;

        if ($id >= 15 && $id <= 26)
            return $this->url_eqdocs;

        return $this->url;
    }

    public function getUrlAttribute($stored)
    {
        return $this->dianUrlFor($stored, 'url');
    }

    public function getUrlPayrollAttribute($stored)
    {
        return $this->dianUrlFor($stored, 'url_payroll');
    }

    public function getUrlEqdocsAttribute($stored)
    {
        return $this->dianUrlFor($stored, 'url_eqdocs');
    }

    public function getUrlSupportDocumentAttribute($stored)
    {
        return $this->dianUrlFor($stored, 'url_support_document');
    }

    public function getUrlEventAttribute($stored)
    {
        return $this->dianUrlFor($stored, 'url_event');
    }
}
