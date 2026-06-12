<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class HealthInvoiceWithoutContract extends Model
{
    protected $fillable = [
        'name', 'code',
    ];
}
