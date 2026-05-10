<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Referencia extends Model
{
    use HasFactory;

    protected $table = 'referencias';

    protected $fillable = [
        'id_cliente',
        'tipo_referencia',
        'nombre',
        'parentesco',
        'direccion',
        'telefono',
        'años_amistad',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }
}
