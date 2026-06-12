<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

/**
 * Respaldo manual generado desde el panel de Backups: un ZIP con los archivos de
 * una empresa (scope 'company') o de todas (scope 'all'). El admin lo descarga y
 * luego puede liberar espacio borrando los archivos reales.
 */
class Backup extends Model
{
    protected $table = 'backups';

    protected $fillable = [
        'scope', 'company_id', 'nit', 'name', 'status',
        'filter_from', 'filter_to', 'filter_label',
        'disk_path', 'size_bytes', 'files_count', 'error',
        'downloaded_at', 'requested_by',
    ];

    protected $casts = [
        'filter_from'   => 'integer',
        'filter_to'     => 'integer',
        'size_bytes'    => 'integer',
        'files_count'   => 'integer',
        'downloaded_at' => 'datetime',
    ];

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /** Ruta absoluta del ZIP en disco (local), o null si no aplica. */
    public function absolutePath(): ?string
    {
        return $this->disk_path ? storage_path('app/' . $this->disk_path) : null;
    }
}
