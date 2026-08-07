<?php

namespace App;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use DateTime;
use App\TypeDocument;

class DocumentPayroll extends Model
{
    use SoftDeletes;

    protected $with = ['type_document'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['identification_number','state_document_id', 'type_document_id', 'prefix', 'consecutive', 'xml', 'pdf', 'cune', 'employee_id', 'date_issue', 'accrued_total', 'deductions_total', 'total_payroll', 'send_email_success', 'send_email_date_time', 'send_email_error'];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['deleted_at'];

    /**
    * Get the type document belongs to
    */
    public function type_document() {
        return $this->belongsTo(TypeDocument::class);
    }


    /**
     * Filtros busqueda nomina
     * Usado en:
     * SendEmailController - sendEmailDocumentPayroll
     * 
     * @param $query
     * @param $company_identification_number
     * @param $prefix
     * @param $number
    */
    public function scopeWhereExistRecord($query, $company_identification_number, $prefix, $consecutive)
    {
        return $query->where('identification_number', $company_identification_number)
                    ->where('prefix', $prefix)
                    ->where('consecutive', $consecutive)
                    ->where('state_document_id', 1);
    }

    /**
     * Búsqueda de nóminas (panel de empresa > Nómina).
     *
     * @param  string|null  $search    Valor (en fecha es el "desde").
     * @param  string|null  $field     Columna: number (consecutivo), prefix, employee, date, type.
     * @param  string|null  $searchTo  "Hasta" del rango de fechas.
     */
    public function scopeFilter($query, $search = null, $field = null, $searchTo = null) {
        $hasValue = !empty($search) || ($field === 'date' && !empty($searchTo));
        if (!$hasValue) {
            return $query;
        }

        if (!empty($field)) {
            switch ($field) {
                case 'number':
                    $query->where(function ($q) use ($search) {
                        // TRIM: prefix es char() y en PostgreSQL llega a CONCAT relleno de espacios
                        $q->where('consecutive', 'LIKE', "%$search%")
                            ->orWhereRaw("CONCAT(COALESCE(TRIM(prefix), ''), COALESCE(consecutive, '')) LIKE ?", ["%$search%"]);
                    });
                    break;
                case 'prefix':
                    $query->where('prefix', 'LIKE', "%$search%");
                    break;
                case 'employee':
                    $query->where('employee_id', 'LIKE', "%$search%");
                    break;
                case 'type':
                    $query->where('type_document_id', $search);
                    break;
                case 'date':
                    if (!empty($search) && !empty($searchTo)) {
                        $query->whereDate('date_issue', '>=', $search)->whereDate('date_issue', '<=', $searchTo);
                    } elseif (!empty($search)) {
                        $query->whereDate('date_issue', $search);
                    } elseif (!empty($searchTo)) {
                        $query->whereDate('date_issue', $searchTo);
                    }
                    break;
            }
            return $query;
        }

        // Búsqueda global (todos los campos).
        // date_issue es datetime: el CAST lo vuelve comparable con LIKE
        // tambien en PostgreSQL (MySQL lo hacia implicito).
        $query->where(function ($q) use ($search) {
            $q->orWhere('consecutive', 'LIKE', "%$search%")
                ->orWhere('prefix', 'LIKE', "%$search%")
                ->orWhereRaw("CONCAT(COALESCE(TRIM(prefix), ''), COALESCE(consecutive, '')) LIKE ?", ["%$search%"])
                ->orWhere('employee_id', 'LIKE', "%$search%")
                ->orWhereRaw(\App\Helpers\SqlDialect::castChar('date_issue') . " LIKE ?", ["%$search%"]);
        });
        return $query;
    }

}
