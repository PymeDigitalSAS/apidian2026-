<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class RipsServiceProvider extends Model
{
    protected $fillable = [
        'company_id',
        'document_type_id',
        'name',
        'email',
        'code',
        'document_number',
    ];

    public function companies()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    // type_document_identifications
    public function documentType()
    {
        return $this->belongsTo(TypeDocumentIdentification::class, 'document_type_id');
    }

    public function scopeFilter($query, $search = null) {
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $like = \App\Helpers\SqlDialect::likeOp(); // insensible a mayusculas en ambos motores
                $q->orWhere('document_number', $like, "%$search%")
                    ->orWhere('name', $like, "%$search%")
                    ->orWhere('code', $like, "%$search%")
                    ->orWhere('email', $like, "%$search%");
            });
        }
        return $query;
    }
}
