<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NominaDetalle extends Model
{
    protected $table = 'nomina_detalle';

    protected $fillable = [
        'periodo_id', 'empleado_id', 'sueldo_bruto', 'retencion_ahorro', 'sueldo_neto',
    ];

    protected $casts = [
        'sueldo_bruto' => 'decimal:2',
        'retencion_ahorro' => 'decimal:2',
        'sueldo_neto' => 'decimal:2',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }
}
