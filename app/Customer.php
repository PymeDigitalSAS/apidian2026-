<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $primaryKey = 'identification_number';

    // PK de texto: sin esto Eloquent asume int y el eager loading usa
    // whereIntegerInRaw (enteros crudos en el SQL), que PostgreSQL rechaza
    // contra una columna varchar. incrementing=false evita ademas que
    // create() pise el NIT en memoria con el lastInsertId.
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'identification_number', 'dv', 'name', 'phone', 'address', 'email', 'password', 'newpassword','companies_id',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class, 'companies_id');
    }
}
