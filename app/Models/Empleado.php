<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    protected $table = 'empleados';

    protected $fillable = [
        'user_id', 'nombre', 'puesto', 'sueldo_base', 'porcentaje_ahorro', 'percepciones_config', 'deducciones_config', 'activo',
        'fecha_nacimiento', 'rfc', 'curp', 'nss', 'banco', 'cuenta_bancaria',
        'despensa', 'apoyo_transporte', 'bono_productividad', 'aportacion_socio'
    ];

    protected $casts = [
        'sueldo_base' => 'decimal:2',
        'porcentaje_ahorro' => 'decimal:2',
        'despensa' => 'decimal:2',
        'apoyo_transporte' => 'decimal:2',
        'bono_productividad' => 'decimal:2',
        'aportacion_socio' => 'decimal:2',
        'fecha_nacimiento' => 'date',
        'percepciones_config' => 'array',
        'deducciones_config' => 'array',
        'activo' => 'boolean',
    ];

    public function ahorro()
    {
        return $this->hasOne(AhorroEmpleado::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
