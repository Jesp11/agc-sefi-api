<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NominaDetalle extends Model
{
    protected $table = 'nomina_detalle';

    protected $fillable = [
        'periodo_id', 'empleado_id', 'sueldo_bruto', 'total_percepciones', 'retencion_ahorro', 'total_deducciones', 'sueldo_neto', 'detalle_ajustes',
    ];

    protected $casts = [
        'sueldo_bruto' => 'decimal:2',
        'total_percepciones' => 'decimal:2',
        'retencion_ahorro' => 'decimal:2',
        'total_deducciones' => 'decimal:2',
        'sueldo_neto' => 'decimal:2',
        'detalle_ajustes' => 'array',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
}
