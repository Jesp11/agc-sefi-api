<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NominaDetalle extends Model
{
    protected $table = 'nomina_detalle';

    protected $fillable = [
        'periodo_id', 'empleado_id', 'asesor_id', 'sueldo_bruto', 'total_percepciones', 'retencion_ahorro', 'total_deducciones', 'sueldo_neto', 'detalle_ajustes',
        'pago_base', 'despensa', 'apoyo_transporte', 'bono_productividad', 'aportacion_socio'
    ];

    protected $casts = [
        'sueldo_bruto' => 'decimal:2',
        'total_percepciones' => 'decimal:2',
        'retencion_ahorro' => 'decimal:2',
        'total_deducciones' => 'decimal:2',
        'sueldo_neto' => 'decimal:2',
        'pago_base' => 'decimal:2',
        'despensa' => 'decimal:2',
        'apoyo_transporte' => 'decimal:2',
        'bono_productividad' => 'decimal:2',
        'aportacion_socio' => 'decimal:2',
        'detalle_ajustes' => 'array',
    ];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class);
    }

    public function asesor()
    {
        return $this->belongsTo(Asesor::class);
    }
}
