<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CicloHistorial extends Model
{
    protected $table = 'ciclos_historial';

    protected $fillable = [
        'id_cliente', 'id_grupo', 'ciclo', 'num_prog',
        'fecha_inicio', 'fecha_fin', 'fecha_consulta', 'resultado', 'snapshot',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'fecha_consulta' => 'date',
        'snapshot' => 'array',
    ];

    public function credito()
    {
        return $this->belongsTo(Credito::class, 'num_prog', 'num_prog');
    }
}
