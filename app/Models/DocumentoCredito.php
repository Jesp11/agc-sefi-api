<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentoCredito extends Model
{
    protected $table = 'documentos_credito';

    protected $fillable = [
        'num_prog',
        'tipo',
        'nombre_archivo',
        'ruta',
        'subido_por',
    ];

    public function credito()
    {
        return $this->belongsTo(Credito::class, 'num_prog', 'num_prog');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
