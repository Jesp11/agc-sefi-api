<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentoCliente extends Model
{
    protected $table = 'documentos_cliente';

    protected $fillable = [
        'id_cliente', 'tipo', 'nombre_archivo', 'ruta', 'subido_por',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }
}
