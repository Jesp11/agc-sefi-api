<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Grupo extends Model
{
    use HasFactory;

    protected $table = 'grupos';

    protected $fillable = [
        'nombre_grupo',
        'id_asesor',
        'es_socio_preferencial',
    ];

    protected $casts = [
        'es_socio_preferencial' => 'boolean',
    ];

    public function clientes()
    {
        return $this->belongsToMany(Cliente::class, 'cliente_grupo', 'id_grupo', 'id_cliente');
    }

    public function asesor()
    {
        return $this->belongsTo(Asesor::class, 'id_asesor');
    }

    public function creditos()
    {
        return $this->hasMany(Credito::class, 'id_grupo');
    }
}
