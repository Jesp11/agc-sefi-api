<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NominaPeriodo extends Model
{
    protected $table = 'nomina_periodos';

    protected $fillable = [
        'fecha_inicio', 'fecha_fin', 'total_dispersado', 'registrado_por',
    ];

    protected $casts = [
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
        'total_dispersado' => 'decimal:2',
    ];

    public function detalles()
    {
        return $this->hasMany(NominaDetalle::class, 'periodo_id');
    }
}
