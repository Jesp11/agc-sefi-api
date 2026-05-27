<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Aval extends Model
{
    use HasFactory;

    protected $table = 'avales';

    protected $fillable = [
        'id_cliente',
        'nombre',
        'direccion',
        'telefono',
        'parentesco',
        'tiempo_conocer',
        'ocupacion_laboral',
        'empresa',
    ];

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente', 'id_cliente');
    }
}
