<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Seller extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $primaryKey = 'identification_number';

    // PK de texto (ver nota en Customer): evita whereIntegerInRaw en PostgreSQL
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'identification_number', 'dv', 'name', 'phone', 'address', 'email', 'password',
    ];
}
