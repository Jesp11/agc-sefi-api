<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Empleado extends Model
{
    protected $table = 'empleados';

    protected $fillable = [
        'nombre', 'puesto', 'sueldo_base', 'porcentaje_ahorro', 'percepciones_config', 'deducciones_config', 'activo',
    ];

    protected $casts = [
        'sueldo_base' => 'decimal:2',
        'porcentaje_ahorro' => 'decimal:2',
        'percepciones_config' => 'array',
        'deducciones_config' => 'array',
        'activo' => 'boolean',
    ];

    public function ahorro()
    {
        return $this->hasOne(AhorroEmpleado::class);
    }
}
