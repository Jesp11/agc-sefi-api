<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cliente extends Model
{
    use HasFactory;

    protected $table = 'clientes';
    protected $primaryKey = 'id_cliente';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id_cliente',
        'id_asesor',
        'nombre_completo',
        'curp',
        'clave_elector',
        'telefono',
        'direccion',
        'entre_calles',
        'ocupacion',
        'direccion_trabajo',
        'telefono_trabajo',
    ];

    public function creditos()
    {
        return $this->hasMany(Credito::class, 'id_cliente', 'id_cliente');
    }

    public function referencias()
    {
        return $this->hasMany(Referencia::class, 'id_cliente', 'id_cliente');
    }

    public function avales()
    {
        return $this->hasMany(Aval::class, 'id_cliente', 'id_cliente');
    }

    public function asesor()
    {
        return $this->belongsTo(Asesor::class, 'id_asesor');
    }
}
